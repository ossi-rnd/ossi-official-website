<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Models\Client;
use App\Models\PatrolCheckpoint;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * A printable sheet of a client's checkpoint QR stickers (A4, cut along the
 * lines). Rendered on the server as SVG, so nothing leaves the app.
 */
final class PrintCheckpointsController extends Controller
{
    public function __invoke(Client $client): View
    {
        Gate::authorize(Ability::ManagePatrols->value);

        $user = request()->user();
        abort_if($user !== null && $user->isRegionallyLimited() && $client->region !== $user->operations_region, 403);

        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        $stickers = PatrolCheckpoint::query()->where('client_id', $client->id)->active()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (PatrolCheckpoint $checkpoint) => ['name' => $checkpoint->name, 'svg' => $writer->writeString($checkpoint->qrPayload())]);

        return view('patrols.print', ['client' => $client, 'stickers' => $stickers]);
    }
}
