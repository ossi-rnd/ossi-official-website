<?php

namespace App\Http\Controllers;

use App\Models\CompanyPolicy;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed copy of a company policy. Every signed-in account may read
 * policies, so the route's `auth` is the check; the file is still private
 * (no public URL to pass around outside the company).
 */
final class ShowPolicyFileController extends Controller
{
    public function __invoke(CompanyPolicy $policy): StreamedResponse
    {
        $disk = Storage::disk((string) config('documents.disk'));
        abort_unless($policy->file_path !== null && $disk->exists($policy->file_path), 404);

        return $disk->response($policy->file_path, $policy->file_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
