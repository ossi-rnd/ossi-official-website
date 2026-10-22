<?php

namespace App\Support;

/**
 * For a queued notification: the bell row (and its live push) is written in
 * the same request, on the "sync" connection; only the email waits for the
 * queue worker. Without it, a stopped worker meant a leave request nobody
 * heard about: its bell item sat in the jobs table with the email.
 *
 * The live push can't break the request: BroadcastNewNotification reports
 * and swallows a Reverb failure.
 */
trait WritesBellImmediately
{
    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }
}
