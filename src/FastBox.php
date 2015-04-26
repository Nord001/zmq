<?php

namespace limitium\zmq;

/**
 * Collect messages from publishers and transfer them to workers
 *
 * Class FastBox
 * @package limitium\zmq
 */
class FastBox
{
    /**
     * @var \ZMQContext
     */
    private $context;
    /**
     * @var \ZMQSocket
     */
    private $backend;
    /**
     * @var \ZMQSocket
     */
    private $frontend;
    private $poll;

    private $verbose;
    // Heartbeat management
    private $heartbeatAt; // When to send HEARTBEAT
    private $heartbeatDelay; // Heartbeat delay, msecs
    private $heartbeatMaxFails = 4;

    private $queue;
    private $queueLimit;
    //workers
    private $workers;
    private $workersFree;

    /**
     * @param $collectEndpoint
     * @param bool $verbose
     * @param int $queueLimit
     * @param int $heartbeatDelay
     */
    public function __construct($collectEndpoint, $verbose = false, $queueLimit = 100, $heartbeatDelay = 2500)
    {
        $this->context = new  \ZMQContext();
        $this->poll = new \ZMQPoll();

        $this->verbose = $verbose;
        $this->heartbeatDelay = $heartbeatDelay;
        $this->queueLimit = $queueLimit;

        $this->queue = array();
        $this->workers = array();
        $this->workersFree = array();

        $this->connect($collectEndpoint);
    }

    private function connect($collectEndpoint)
    {
        if ($this->frontend) {
            $this->poll->remove($this->frontend);
            unset($this->frontend);
        }

        $this->frontend = $this->context->getSocket(\ZMQ::SOCKET_SUB);
        $this->frontend->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
        $this->frontend->setSockOpt(\ZMQ::SOCKOPT_SUBSCRIBE, "");
        $this->frontend->connect($collectEndpoint);
        $this->poll->add($this->frontend, \ZMQ::POLL_IN);

        if ($this->verbose) {
            printf("I: Connecting to publisher at %s... %s", $this->publisher, PHP_EOL);
        }
    }

    public function bind($workersEndpoint)
    {
        $this->backend = $this->context->getSocket(\ZMQ::SOCKET_XREP);
        $this->backend->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
        $this->backend->bind($workersEndpoint);
        $this->poll->add($this->backend, \ZMQ::POLL_IN);
        if ($this->verbose) {
            printf("I: FastBox is active at %s %s", $workersEndpoint, PHP_EOL);
        }

    }

    public function transfer()
    {
        $read = $write = array();
        while (1) {
            $events = $this->poll->poll($read, $write, $this->heartbeatDelay);
            if ($events > 0) {
                foreach ($read as $socket) {
                    //handle workers
                    if ($socket === $this->backend) {
                        $zmsg = new Zmsg($this->backend);
                        $zmsg->recv();
                        if ($this->verbose) {
                            echo "I: received message:", PHP_EOL, $zmsg->__toString(), PHP_EOL;
                        }

                        $sender = $zmsg->pop();
                        $empty = $zmsg->pop();
                        $header = $zmsg->pop();
                        if ($header == Commands::W_WORKER) {
                            $this->process($sender, $zmsg);
                        } else {
                            echo "E: invalid header `$header` in  message", PHP_EOL, $zmsg->__toString(), PHP_EOL, PHP_EOL;
                        }
                    }
                    //handle publisher
                    if ($socket === $this->frontend) {
                        $zmsg = new Zmsg($this->frontend);
                        $zmsg->recv();
                        if ($this->verbose) {
                            echo "I: received message from publisher size: ";
                            echo strlen($zmsg->__toString()), PHP_EOL;
                        }
                        $time = $zmsg->unwrap();
                        if ($this->queueLimit > sizeof($this->queue)) {
                            array_unshift($this->queue, $zmsg->pop());
                        }
                    }
                }
            }

            $this->generateTasks();
            $this->sendHeartbeats();
        }
    }

    private function sendHeartbeats()
    {
        if (microtime(true) > $this->heartbeatAt) {
            if ($this->verbose) {
                echo "I: send heartbeats to " . sizeof($this->workersFree) . " workers", PHP_EOL;
            }
            foreach ($this->workersFree as $worker) {
                $this->workerSend($worker, Commands::W_HEARTBEAT);
            }
            $this->heartbeatAt = microtime(true) + ($this->heartbeatDelay / 1000);
        }
    }

    private function process($sender, $zmsg)
    {
        $command = $zmsg->pop();
        $hasWorker = $this->hasWorker($sender);

        switch ($command) {
            case Commands::W_READY:
                if (!$hasWorker) {
                    $this->addWorker($sender);
                } else {
                    echo "E: Ready from ready worker `$sender` - disconnect ", PHP_EOL;
                    $this->deleteWorker($this->workers[$sender], true);
                }
                break;
            case Commands::W_HEARTBEAT:
                if ($hasWorker) {
                    $this->live($this->workers[$sender]);
                } else {
                    echo "E: Heartbeat from not ready worker `$sender` - disconnect ", PHP_EOL;
                    $this->send($sender, Commands::W_RESPONSE);
                }
                break;
            case Commands::W_RESPONSE:
                if ($hasWorker) {
                    $resp = $zmsg->pop();
                    $this->free($this->workers[$sender]);
                } else {
                    echo "E: Response from not ready worker `$sender` - disconnect ", PHP_EOL;
                    $this->send($sender, Commands::W_RESPONSE);
                }
                break;
            default:
                echo "E: Unsupported command `$command`.", PHP_EOL;
                echo $zmsg->__toString(), PHP_EOL, PHP_EOL;
        }
    }

    private function addWorker($address)
    {
        if ($this->verbose) {
            echo "I: add new worker:", PHP_EOL;
        }
        $worker = new WorkerAddress($address);
        $this->workers[$address] = $worker;
        $this->free($worker);
        return $worker;
    }

    private function free($worker)
    {
        $this->workersFree[] = $worker;
        $this->live($worker);
        $this->generateTasks();
    }

    private function live(WorkerAddress $worker)
    {
        $worker->aliveFor($this->heartbeatMaxFails * $this->heartbeatDelay);
    }

    private function hasWorker($address)
    {
        return isset($this->workers[$address]);
    }

    private function workerSend(WorkerAddress $worker, $command, $data = null)
    {
        $zmsg = null;
        if ($data) {
            $zmsg = new Zmsg();
            $zmsg->body_set($data);
        }
        $this->send($worker->address, $command, $zmsg);
    }

    private function send($address, $command, $zmsg = null)
    {
        $zmsg = $zmsg ? $zmsg : new Zmsg();
        $zmsg->push($command);
        $zmsg->push(Commands::W_WORKER);
        $zmsg->wrap($address, "");
        if ($this->verbose) {
            printf("I: sending `%s` to worker %s", $command, PHP_EOL);
            echo $zmsg->__toString(), PHP_EOL, PHP_EOL;
        }
        $zmsg->set_socket($this->backend)->send();
    }

    private function purgeWorkers()
    {
        foreach ($this->workersFree as $worker) {
            if ($worker->expiry < microtime(1)) {
                echo "I: expired worker `$worker->address`", PHP_EOL;
                $this->deleteWorker($worker);
            }
        }
    }

    private function deleteWorker(WorkerAddress $worker, $disconnect = false)
    {
        if ($this->verbose) {
            echo "I: remove worker `$worker->address` " . ($disconnect ? "disconnect" : ""), PHP_EOL;
        }
        if ($disconnect) {
            $this->workerSend($worker, Commands::W_RESPONSE);
        }
        unset($this->workers[$worker->address]);
        $index = array_search($worker, $this->workersFree);
        if ($index !== false) {
            unset($this->workersFree[$index]);
        }
    }

    private function generateTasks()
    {
        $this->purgeWorkers();
        foreach ($this->workersFree as $k => $worker) {
            $task = array_shift($this->queue);
            if ($task) {
                $this->workerSend($worker, Commands::W_REQUEST, $task);
                unset($this->workersFree[$k]);
            }
        }
    }
}
