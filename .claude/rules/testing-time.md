---
paths:
  - "tests/**"
---

### Freeze time in any test whose subject is a time WINDOW or a cache TTL

If what the test asserts is defined by a period — a rate-limit window, a
`Cache::remember()` TTL, a "stale after N minutes" reaper, a job's `retry_after`
— call `$this->freezeTime()` first. There's nothing to undo: a freeze in one
test does not leak into the next (verified 2026-08-07 — `Carbon::hasTestNow()`
Real incident (2026-08-07): `it('throttles repeated login attempts')` failed
~35% of runs — six bad logins against `throttle:6,1`, and the 7th intermittently
got 200. Two mechanisms combined, and both are worth knowing:

1. **`RateLimiter::tooManyAttempts()` silently RESETS the counter.** It reports
   "too many" only while a companion `{key}:timer` cache entry still exists; if
   the counter is at the limit but the timer is gone, it calls `resetAttempts()`
   and returns **false**. The two share one TTL, so losing them mid-test doesn't
   just lose the count — it hands out a fresh allowance.
2. **The host clock can step.** On this WSL2 box (`timedatectl` reports
   `System clock synchronized: no`) one request logged a timestamp 66s ahead of
   the requests either side of it — past the 60s TTL, so both entries expired.

Freezing fixes it for the right reason rather than hiding a bad clock: on a slow
CI box six bcrypt hashes could legitimately straddle a real minute boundary.

Debugging recipe when a time-ish test flakes: log `time()`,
`Carbon::now()->getTimestamp()` and `Carbon::hasTestNow()` at each step. If the
first two agree with each other but jump around, it's the machine — and a tight
`microtime(true)` loop will NOT reproduce it, because the step happens between
requests, not inside a burst.
