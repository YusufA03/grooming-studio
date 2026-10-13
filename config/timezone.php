<?php
// All times in the system are South African time. The times saved in the
// database are local times, so every page and class must use this same clock.
// This is the ONE place that sets it.
date_default_timezone_set('Africa/Johannesburg');
