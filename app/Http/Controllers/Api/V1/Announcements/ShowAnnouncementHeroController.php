<?php

namespace App\Http\Controllers\Api\V1\Announcements;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/me/announcements/{id}/hero/{media} — streams the notice artwork from the PRIVATE
 * disk, gated on the caller's own recipient row.
 *
 * **This is why the collection is private while `marketing_posts`' hero is public.** A marketing
 * card is read by unauthenticated shoppers, so its image has to be fetchable without a session. A
 * tenant notice is read by the tenants of one mall and its artwork can be an evacuation plan, a
 * service-corridor diagram, or a letter with a signature on it. A public URL for that would be
 * enumerable by anyone who guessed a media id, which is exactly the hardening the request and
 * sales-declaration attachments already went through.
 *
 * A notice the caller was never sent 404s before any file is touched — same no-enumeration rule as
 * the detail endpoint.
 */
class ShowAnnouncementHeroController extends ApiController
{
    public function __invoke(Request $request, int $id, int $media): StreamedResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;

        $announcement = Announcement::query()->liveFor($tenant)->findOrFail($id);

        $item = $announcement->getMedia(Announcement::HERO_COLLECTION)->firstWhere('id', $media);
        abort_if($item === null, 404);

        return $item->toInlineResponse($request);
    }
}
