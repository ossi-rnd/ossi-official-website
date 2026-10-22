<?php

namespace App\Actions\Patrols;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\AwayLog;
use App\Models\SentAlert;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\PatrolAlert;
use App\Queries\Attendance\PatrolRoundsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run every 15 minutes. Sends, once each:
 *  - a roving guard missed a patrol round (a checkpoint not scanned in time);
 *  - a round looks faked (scanned in seconds, or all from one spot);
 *  - someone away is well past the time they said they'd be back, without
 *    asking for more (once per expected time: adding time and running late
 *    again alerts again);
 *  - someone away hasn't shared their location for a long while.
 * Merely running a little late, or a reminder to share, is never an alert.
 *
 * Guards' alerts go to Operation HQ and the region's Satellite Operation;
 * office staff's to the President or Vice President (monitor-office-away), like their leave.
 */
final class SendPatrolAlerts
{
    public function __construct(private readonly PatrolRoundsQuery $rounds) {}

    /**
     * @return int how many alerts were sent
     */
    public function handle(CarbonImmutable $now): int
    {
        $operations = User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations])->with('roles')->get();
        $executives = User::query()->notifiable(Ability::MonitorOfficeAway->roles())->get();

        return $this->missedRounds($now, $operations) + $this->lateAway($now, $operations, $executives) + $this->overdueLocation($now, $operations, $executives);
    }

    /**
     * @param  Collection<int, User>  $operations
     */
    private function missedRounds(CarbonImmutable $now, Collection $operations): int
    {
        if ($operations->isEmpty()) {
            return 0;
        }

        $logs = TimeLog::query()
            ->whereHas('dutyAssignment', fn ($query) => $query->where('patrols', true))
            ->whereDate('log_date', '>=', $now->subDay()->toDateString())
            ->whereNotNull('clock_in_at')
            ->with(['user', 'dutyAssignment.client', 'dutyAssignment.shift'])
            ->get();

        $sent = 0;

        foreach ($logs as $log) {
            foreach ($this->rounds->handle($log, $now)['rounds'] as $round) {
                $client = $log->dutyAssignment?->client;

                // Scanned, but not walked: photos of the stickers, from one spot or in seconds.
                if ($round['suspicious'] !== null) {
                    $sent += $this->send("patrol.suspicious.{$round['index']}", $log, $this->operationsFor($operations, $client?->region),
                        new PatrolAlert($log->user->name, "Round {$round['index']} looks faked at ".($client->name ?? 'the post'), ucfirst($round['suspicious']).' ('.$round['from']->format('g:i').'–'.$round['to']->format('g:i A').'). Check the scan photos on Patrols, or call the guard.', route('patrols')));
                }

                if ($round['state'] !== 'missed') {
                    continue;
                }

                $sent += $this->send("patrol.missed.{$round['index']}", $log, $this->operationsFor($operations, $client?->region),
                    new PatrolAlert($log->user->name, "Round {$round['index']} missed ({$round['from']->format('g:i')}–{$round['to']->format('g:i A')}) at ".($client->name ?? 'the post'), 'Not scanned: '.implode(', ', $round['missing']).'.'));
            }
        }

        return $sent;
    }

    /**
     * @param  Collection<int, User>  $operations
     * @param  Collection<int, User>  $executives
     */
    private function lateAway(CarbonImmutable $now, Collection $operations, Collection $executives): int
    {
        $sent = 0;

        foreach (AwayLog::query()->open()->whereNull('approved_at')->with(['user.employeeProfile', 'timeLog.dutyAssignment.client'])->get() as $away) {
            if (! $away->isLate($now)) {
                continue;
            }

            [$recipients, $where, $page, $url] = $this->audience($away, $operations, $executives);
            $sent += $this->send('away.late.'.$away->expectedBackAt()->format('YmdHis'), $away, $recipients,
                new PatrolAlert($away->user->name, "Not back yet: {$away->minutesPastExpected($now)} min past {$away->expectedBackAt()->format('g:i A')} ({$away->reason->label()})", ($away->note ?? 'No note.')." Away from {$where} since {$away->left_at->format('g:i A')}; no more time asked. Call them, or approve it on {$page}.", $url));
        }

        return $sent;
    }

    /**
     * @param  Collection<int, User>  $operations
     * @param  Collection<int, User>  $executives
     */
    private function overdueLocation(CarbonImmutable $now, Collection $operations, Collection $executives): int
    {
        $sent = 0;

        foreach (AwayLog::query()->open()->with(['user.employeeProfile', 'latestLocation', 'timeLog.dutyAssignment.client'])->get() as $away) {
            if (! $away->isLocationOverdue($now)) {
                continue;
            }

            // Once per silence: keyed on the last time they shared, so a new
            // update and then another silence alerts again, but no repeats.
            [$recipients, , $page, $url] = $this->audience($away, $operations, $executives);
            $sent += $this->send('away.location.'.$away->lastSeenAt()->format('YmdHis'), $away, $recipients,
                new PatrolAlert($away->user->name, 'No location shared for '.(int) $away->lastSeenAt()->diffInMinutes($now).' min while away ('.$away->reason->label().')', 'Last seen '.$away->lastSeenAt()->format('g:i A').'; expected back by '.$away->expectedBackAt()->format('g:i A').". Call them, or check {$page}.", $url));
        }

        return $sent;
    }

    /**
     * Who hears about this absence, and where to send them.
     *
     * @param  Collection<int, User>  $operations
     * @param  Collection<int, User>  $executives
     * @return array{0: Collection<int, User>, 1: string, 2: string, 3: string}
     */
    private function audience(AwayLog $away, Collection $operations, Collection $executives): array
    {
        if ($away->user->employeeProfile->is_office_staff ?? true) {
            return [$executives, 'the office', 'Office Away', route('office-away')];
        }

        return [$this->operationsFor($operations, $away->timeLog->dutyAssignment?->client?->region), 'the post', 'Patrols', route('patrols')];
    }

    /**
     * Operation HQ always; a Satellite Operation only for its own region.
     *
     * @param  Collection<int, User>  $operations
     * @return Collection<int, User>
     */
    private function operationsFor(Collection $operations, mixed $region): Collection
    {
        return $operations->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))->values();
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function send(string $alert, TimeLog|AwayLog $subject, Collection $recipients, PatrolAlert $notice): int
    {
        if ($recipients->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($alert, $subject, $recipients, $notice) {
            if (! SentAlert::claim($alert, $subject)) {
                return 0;
            }

            Notification::send($recipients, $notice);

            return 1;
        });
    }
}
