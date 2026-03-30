<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppMessageParser;
use Tests\TestCase;

class WhatsAppMessageParserTest extends TestCase
{
    public function test_it_extracts_interactive_button_reply_from_webhook_payload(): void
    {
        $parser = app(WhatsAppMessageParser::class);

        $messages = $parser->extractMessages($this->payloadWithMessage([
            'from' => '6281234567890',
            'id' => 'wamid.button.1',
            'timestamp' => '1710000000',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button_reply',
                'button_reply' => [
                    'id' => 'contact_same',
                    'title' => 'Sama',
                ],
            ],
        ]));

        $this->assertCount(1, $messages);
        $this->assertSame('interactive', $messages[0]['message_type']);
        $this->assertSame('Sama', $messages[0]['message_text']);
        $this->assertSame('button_reply', $messages[0]['interactive_reply']['type']);
        $this->assertSame('contact_same', $messages[0]['interactive_reply']['id']);
        $this->assertSame('Sama', $messages[0]['raw_payload']['_interactive_selection']['title']);
    }

    public function test_it_extracts_interactive_list_reply_from_webhook_payload(): void
    {
        $parser = app(WhatsAppMessageParser::class);

        $messages = $parser->extractMessages($this->payloadWithMessage([
            'from' => '6281234567890',
            'id' => 'wamid.list.1',
            'timestamp' => '1710000001',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list_reply',
                'list_reply' => [
                    'id' => 'pickup_location:pasir-pengaraian',
                    'title' => 'Pasir Pengaraian',
                    'description' => 'Lokasi jemput',
                ],
            ],
        ]));

        $this->assertCount(1, $messages);
        $this->assertSame('Pasir Pengaraian', $messages[0]['message_text']);
        $this->assertSame('list_reply', $messages[0]['interactive_reply']['type']);
        $this->assertSame('pickup_location:pasir-pengaraian', $messages[0]['interactive_reply']['id']);
        $this->assertSame('Lokasi jemput', $messages[0]['interactive_reply']['description']);
        $this->assertSame('Pasir Pengaraian', $messages[0]['raw_payload']['_interactive_selection']['title']);
    }

    public function test_it_can_humanize_interactive_ids_when_title_is_missing(): void
    {
        $parser = app(WhatsAppMessageParser::class);

        $text = $parser->extractText([
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button_reply',
                'button_reply' => [
                    'id' => 'booking_confirm',
                ],
            ],
        ]);

        $this->assertSame('benar', $text);
    }

    public function test_it_normalizes_booking_confirm_button_to_benar_even_when_title_exists(): void
    {
        $parser = app(WhatsAppMessageParser::class);

        $messages = $parser->extractMessages($this->payloadWithMessage([
            'from' => '6281234567890',
            'id' => 'wamid.button.2',
            'timestamp' => '1710000002',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button_reply',
                'button_reply' => [
                    'id' => 'booking_confirm',
                    'title' => 'Benar',
                ],
            ],
        ]));

        $this->assertSame('benar', $messages[0]['message_text']);
    }

    public function test_it_extracts_statuses_from_webhook_payload(): void
    {
        $parser = app(WhatsAppMessageParser::class);

        $statuses = $parser->extractStatuses([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '6281234567890',
                            'phone_number_id' => '123456',
                        ],
                        'statuses' => [[
                            'id' => 'wamid.status.1',
                            'recipient_id' => '6281234567890',
                            'status' => 'delivered',
                            'timestamp' => '1710000003',
                            'conversation' => [
                                'id' => 'conversation-1',
                            ],
                            'pricing' => [
                                'billable' => true,
                            ],
                            'errors' => [],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $this->assertCount(1, $statuses);
        $this->assertSame('wamid.status.1', $statuses[0]['wa_message_id']);
        $this->assertSame('delivered', $statuses[0]['status']);
        $this->assertSame('6281234567890', $statuses[0]['recipient_id']);
        $this->assertSame('123456', $statuses[0]['metadata']['phone_number_id']);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function payloadWithMessage(array $message): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '6281234567890',
                            'phone_number_id' => '123456',
                        ],
                        'contacts' => [[
                            'wa_id' => '6281234567890',
                            'profile' => ['name' => 'Nerry'],
                        ]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }
}
