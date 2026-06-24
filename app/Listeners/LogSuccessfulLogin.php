<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Auth\Events\Login;
use App\Services\ActivityLog\ActivityService;
use Illuminate\Support\Facades\Request;
class LogSuccessfulLogin
{
    /**
     * Create the event listener.
     */
    protected $activity;
    public function __construct(ActivityService $activity)
    {
        $this->activity = $activity;
    }

    public function handle(Login $event)
    {
        $this->activity->log(
            $event->user->id, 
            "User logged in from IP: " . Request::ip()
        );
    }
}
