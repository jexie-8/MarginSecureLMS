<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * For demonstrations, every email can be redirected to one inbox. Real
 * deployments leave it unset and mail goes to the real recipient.
 */
class MailRedirectTest extends TestCase
{
    private function recipients(): array
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $addresses = [];
        foreach ($transport->messages() as $sent) {
            foreach ($sent->getEnvelope()->getRecipients() as $recipient) {
                $addresses[] = $recipient->getAddress();
            }
        }

        return $addresses;
    }

    public function test_nothing_is_redirected_by_default(): void
    {
        $this->assertEmpty(config('margin.mail_always_to'));

        config(['mail.default' => 'array']);
        Mail::raw('hello', fn ($m) => $m->to('student@margin.test')->subject('Test'));

        $this->assertSame(['student@margin.test'], $this->recipients());
    }

    public function test_when_set_all_mail_goes_to_the_redirect_address(): void
    {
        config(['mail.default' => 'array', 'margin.mail_always_to' => 'inbox@example.test']);
        (new AppServiceProvider($this->app))->boot();

        Mail::raw('hello', fn ($m) => $m->to('student@margin.test')->subject('Test'));

        $this->assertSame(['inbox@example.test'], $this->recipients());
    }
}
