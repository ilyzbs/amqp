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

use Hyperf\Amqp\Message\MessageInterface;
use Hyperf\Amqp\Pool\PoolFactory;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Psr\Container\ContainerInterface;

class Builder
{
    protected $name = 'default';

    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function declare(MessageInterface $message, ?AMQPChannel $channel = null): void
    {
        if (! $channel) {
            $channel = $this->getChannel($message->getPoolName());
        }

        $builder = $message->getExchangeDeclareBuilder();

        $channel->exchange_declare(
            $builder->getExchange(),
            $builder->getType(),
            $builder->isPassive(),
            $builder->isDurable(),
            $builder->isAutoDelete(),
            $builder->isInternal(),
            $builder->isNowait(),
            $builder->getArguments(),
            $builder->getTicket()
        );
    }

    protected function getChannel(string $poolName, ?Connection $conn = null): AMQPChannel
    {
        if (empty($conn)) {
            /** @var Connection $conn */
            $conn = $this->getConnection($poolName);
        }

        $connection = $conn->getConnection();
        try {
            $channel = $connection->channel();
        } catch (AMQPRuntimeException $ex) {
            // Fetch channel failed, try again.
            $connection->reconnect();
            $channel = $connection->channel();
        }

        return $channel;
    }

    protected function getConnection(string $poolName): Connection
    {
        /** @var PoolFactory $factory */
        $factory = $this->container->get(PoolFactory::class);
        $pool = $factory->getAmqpPool($poolName);
        return $pool->get();
    }

    protected function release(Connection $connection)
    {
        $connection->release();
    }
}
