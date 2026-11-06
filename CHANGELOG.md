# Changelog

All notable changes to `wfeller/laravel-schedule-monitor-healthchecksio` will be documented in this file

## 1.0.1 - 2026-11-06

- stop dropping late success pings because of a newer success, or because of a newer start when start signals are off or that run has already ended. Under a queue backlog longer than a task's period every success ping was dropped, and the check went down although the task kept succeeding. A late success is now dropped only when a newer run failed, or when start signals are on and a newer run is still going.

## 1.0.0 - 2026-11-05

- initial release
