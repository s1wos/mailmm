<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mail:prune')->everyMinute()->withoutOverlapping(10);
Schedule::command('mail:prune-quota')->hourly()->withoutOverlapping();
Schedule::command('mail:recover-forwards')->everyMinute()->withoutOverlapping(5);
Schedule::command('logs:prune')->hourly()->withoutOverlapping();
