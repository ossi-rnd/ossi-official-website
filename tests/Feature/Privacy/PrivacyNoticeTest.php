<?php

use App\Models\User;

// The privacy notice is an ACKNOWLEDGEMENT (RA 10173 sec. 16(a), the right to be
// informed), never a consent form: consent given to keep a job isn't freely given,
// and the company relies on secs. 12(b)(c)(f) and 13(b)(f) instead. See
// config/privacy.php. These tests keep the wording from drifting into "consent".

/** The page as someone who still has to acknowledge it sees it, as plain text. */
function privacyNoticeText(): string
{
    $html = test()->actingAs(User::factory()->privacyPending()->create())->get(route('privacy-notice'))->assertOk()->getContent();

    return (string) preg_replace('/\s+/', ' ', strip_tags((string) $html));
}

it('asks people to acknowledge they were informed, and says it is not consent', function () {
    $text = privacyNoticeText();

    expect($text)
        ->toContain('I have read and understood this notice')
        ->toContain('This records that you were informed; it is not a consent form, and it does not waive any of the rights described above.')
        ->toContain('We do not rely on your consent for any of the above.');
});

it('never asks anyone to consent, agree or accept', function () {
    $text = strtolower(privacyNoticeText());

    foreach (['i consent', 'i give my consent', 'i hereby consent', 'i agree', 'i accept', 'consent form below', 'by signing in you consent', 'you consent to'] as $phrase) {
        expect($text)->not->toContain($phrase);
    }
});

it('words every mention of consent in the notice as something not relied on', function () {
    // Each sentence of the notice that mentions consent must say it isn't relied on,
    // or that this isn't a consent form; a new line asking for consent fails here.
    $sentences = preg_split('/(?<=[.!?])\s+/', privacyNoticeText()) ?: [];
    $aboutConsent = array_values(array_filter($sentences, fn (string $sentence) => stripos($sentence, 'consent') !== false));

    expect($aboutConsent)->not->toBeEmpty();

    foreach ($aboutConsent as $sentence) {
        expect(preg_match('/\b(not|never|no)\b/i', $sentence))->toBe(1, "Mentions consent without saying it isn't relied on: \"{$sentence}\"");
    }
});

it('shows the same notice to someone who hasn\'t signed in, without the button', function () {
    $this->get(route('privacy-notice'))->assertOk()
        ->assertSee('We do not rely on your consent')
        ->assertDontSee('I have read and understood this notice');
});
