<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use Modules\Marketing\Contracts\ChannelProviderInterface;
use Modules\Marketing\Models\Channel;

/**
 * Resolves the appropriate ChannelProvider implementation for a given
 * Channel model. Supports runtime registration via the service container.
 */
final class ChannelManager
{
    /** @var array<string, ChannelProviderInterface> */
    private array $providers = [];

    public function register(ChannelProviderInterface $provider): void
    {
        $this->providers[$provider->type()] = $provider;
    }

    public function resolve(Channel $channel): ChannelProviderInterface
    {
        $type = $channel->type;

        if (!isset($this->providers[$type])) {
            throw new \RuntimeException("No channel provider registered for type [{$type}].");
        }

        return $this->providers[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->providers[$type]);
    }

    /** @return string[] */
    public function supportedTypes(): array
    {
        return array_keys($this->providers);
    }
}
