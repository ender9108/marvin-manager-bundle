<?php

namespace EnderLab\MarvinManagerBundle\Messenger\Serializer;

use EnderLab\MarvinManagerBundle\List\ManagerMessageReference;
use EnderLab\MarvinManagerBundle\Messenger\Attribute\AsMessageType;
use EnderLab\MarvinManagerBundle\Messenger\ManagerRequestMessage;
use EnderLab\MarvinManagerBundle\Messenger\ManagerResponseMessage;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceivedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ManagerSerializer implements SerializerInterface
{
    private array $handlers = [];

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ParameterBagInterface $parameters,
        private readonly ValidatorInterface $validator,
        #[AutowireIterator('marvin.message.handler')]
        iterable $handlersByType,
    ) {
        $this->handlers = $this->buildHandlers($handlersByType);
    }

    /**
     * @throws Exception
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $body = json_decode($encodedEnvelope['body'], true, 512, JSON_THROW_ON_ERROR);
        $headers = $encodedEnvelope['headers'];

        $this->validateEnvelope($body, $headers);

        $messageClass = $this->handlers[$headers['type']] ?? null;

        if (null === $messageClass) {
            throw new MessageDecodingFailedException(sprintf('Message class not found (type: %s)', $headers['type']));
        }

        $message = new $messageClass($body);
        $violations = $this->validator->validate($message);

        if (count($violations) > 0) {
            $message = '';

            foreach ($violations as $violation) {
                $message .= $violation->getMessage() . "\n";
            }

            throw new ValidationFailedException($message);
        }

        return new Envelope($message);
    }

    /**
     * @throws Exception
     */
    public function encode(Envelope $envelope): array
    {
        /** @var ManagerResponseMessage|ManagerRequestMessage $message */
        $message = $envelope->getMessage();

        $violations = $this->validator->validate($message);

        if (count($violations) > 0) {
            $message = '';

            foreach ($violations as $violation) {
                $message .= $violation->getMessage() . "\n";
            }

            throw new ValidationFailedException($message);
        }

        $type = $this->getBindingTypeMessage($message);

        $envelope = match (true) {
            $message instanceof ManagerResponseMessage => $envelope->withoutStampsOfType(BusNameStamp::class),
            $message instanceof ManagerRequestMessage => $envelope->withoutStampsOfType(AckStamp::class),
            default => throw new Exception(sprintf('Invalid message type %s', get_class($message))),
        };

        return [
            'body' => json_encode($message->payload),
            'headers' => [
                'type' => $type,
                'stamps' => serialize($envelope->all())
            ],
        ];
    }

    /**
     * @throws Exception
     */
    private function validateEnvelope(array $message, array $headers): void
    {
        if (
            !isset($headers['type'])
            || !in_array($headers['type'], ManagerMessageReference::getConstantsList(), true)
        ) {
            throw new MessageDecodingFailedException('Unsupported message type.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function buildHandlers(iterable $handlersByType)
    {
        $key = md5('marvin_core_request_'.count(iterator_to_array($handlersByType)));

        return $this->cache->get($key, function (ItemInterface $item) use ($handlersByType): array {
            $item->expiresAfter($this->parameters->get('cache_timeout'));
            $mapping = [];

            foreach ($handlersByType as $handler) {
                $reflectionClass = new \ReflectionClass($handler);
                $attributes = $reflectionClass->getAttributes(name: AsMessageType::class);

                if (count($attributes) === 1) {
                    $arguments = $attributes[0]->getArguments();

                    if (in_array($arguments['binding'], ManagerMessageReference::getConstantsList(), true)) {
                        $mapping[$arguments['binding']] = get_class($handler);
                    }
                }
            }

            return $mapping;
        });
    }

    private function getBindingTypeMessage(ManagerResponseMessage|ManagerRequestMessage $message): ?string
    {
        $reflectionClass = new \ReflectionClass($message);
        $attributes = $reflectionClass->getAttributes(name: AsMessageType::class);

        if (count($attributes) === 1) {
            $arguments = $attributes[0]->getArguments();

            if (in_array($arguments['binding'], ManagerMessageReference::getConstantsList(), true)) {
                return $arguments['binding'];
            }
        }

        return null;
    }
}
