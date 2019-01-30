<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.org
 * @document https://wiki.hyperf.org
 * @contact  group@hyperf.org
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Amqp;

use Hyperf\Amqp\Connection\AMQPSwooleConnection;
use Hyperf\Amqp\Pool\AmqpConnectionPool;
use Hyperf\Contract\ConnectionInterface;
use Hyperf\Pool\Connection as BaseConnection;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Coroutine;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Container\ContainerInterface;

class Connection extends BaseConnection implements ConnectionInterface
{
    /**
     * @var AmqpConnectionPool
     */
    protected $pool;

    /**
     * @var AbstractConnection
     */
    protected $connection;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var Params
     */
    protected $params;

    /**
     * @var float
     */
    protected $lastHeartbeatTime = 0.0;

    protected $transaction = false;

    public function __construct(ContainerInterface $container, AmqpConnectionPool $pool, array $config)
    {
        parent::__construct($container, $pool);
        $this->config = $config;
        $this->context = $container->get(Context::class);
        $this->params = new Params(Arr::get($config, 'params', []));
        $this->connection = $this->initConnection();
    }

    public function __call($name, $arguments)
    {
        return $this->connection->{$name}(...$arguments);
    }

    public function getConnection(): AbstractConnection
    {
        if ($this->check()) {
            return $this->connection;
        }

        $this->reconnect();

        return $this->connection;
    }

    public function getChannel($confirm = false): AbstractChannel
    {
        $connection = $this->getConnection();
        $needConfirmSelect = true;
        $channelId = 1;
        ! $confirm && $channelId = 2;
        if ($confirm && isset($connection->channels[$channelId])) {
            $needConfirmSelect = false;
        }
        $channel = $connection->channel($channelId);
        $needConfirmSelect && $channel->confirm_select(false);
        return $channel;
    }

    public function reconnect(): bool
    {
        $this->connection->reconnect();
        return true;
    }

    public function check(): bool
    {
        return isset($this->connection) && $this->connection instanceof AbstractConnection && $this->connection->isConnected() && ! $this->isHeartbeatTimeout();
    }

    public function close(): bool
    {
        $this->connection->close();
        return true;
    }

    public function release(): void
    {
        parent::release();
    }

    protected function initConnection(): AbstractConnection
    {
        $class = AMQPStreamConnection::class;
        if (Coroutine::id() > 0) {
            $class = AMQPSwooleConnection::class;
        }

        return new $class($this->config['host'] ?? 'localhost', $this->config['port'] ?? 5672, $this->config['user'] ?? 'guest', $this->config['password'] ?? 'guest', $this->config['vhost'] ?? '/', $this->params->isInsist(), $this->params->getLoginMethod(), $this->params->getLoginResponse(), $this->params->getLocale(), $this->params->getConnectionTimeout(), $this->params->getReadWriteTimeout(), $this->params->getContext(), $this->params->isKeepalive(), $this->params->getHeartbeat());
    }

    protected function isHeartbeatTimeout(): bool
    {
        if ($this->params->getHeartbeat() === 0) {
            return false;
        }

        $lastHeartbeatTime = $this->lastHeartbeatTime;
        $currentTime = microtime(true);
        $this->lastHeartbeatTime = $currentTime;

        if ($lastHeartbeatTime && $lastHeartbeatTime > 0) {
            if ($currentTime - $lastHeartbeatTime > $this->params->getHeartbeat()) {
                return true;
            }
        }

        return false;
    }

}
