<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:prune')->dailyAt('02:15')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed --hours=2160')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
