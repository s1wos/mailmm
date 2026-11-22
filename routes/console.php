<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mail:prune')->everyMinute()->withoutOverlapping(10);
Schedule::command('mail:prune-quota')->hourly()->withoutOverlapping();
