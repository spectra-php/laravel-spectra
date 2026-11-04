<?php

declare(strict_types=1);

namespace Spectra\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Spectra\Support\Alerts\AlertRule;

/**
 * The email `spectra.alerts.notify.mail` gets when an alert fires or resolves.
 */
class AlertMail extends Mailable
{
    /**
     * Create a new alert mail instance.
     */
    public function __construct(
        public readonly AlertRule $rule,
        public readonly ?float $value,
        public readonly bool $resolved,
        public readonly string $dashboardUrl,
    ) {}

    /**
     * Get the message's subject.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->resolved ? 'Resolved: ' : 'Alert: ').$this->rule->name);
    }

    /**
     * Get the message's body.
     */
    public function content(): Content
    {
        $value = $this->value !== null ? e($this->rule->metric->format($this->value)) : 'not enough calls to measure';
        $state = $this->resolved ? 'is back to normal' : 'is firing';

        return new Content(htmlString: '<p><strong>'.e($this->rule->name)."</strong> {$state}.</p>"
            .'<p>'.e($this->rule->describe()).'<br>Now: '.$value.'</p>'
            .'<p><a href="'.e($this->dashboardUrl).'">Open the Spectra dashboard</a></p>');
    }
}
