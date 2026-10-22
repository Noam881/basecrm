<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('crm:remind')->everyMinute()->withoutOverlapping();
