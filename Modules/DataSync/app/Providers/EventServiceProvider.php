<?php

declare(strict_types=1);

namespace Modules\DataSync\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /** @var bool */
    protected static $shouldDiscoverEvents = true;

    /** @var array<class-string, list<class-string>> */
    protected $listen = [];
}
