<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Enums\SupportTicketType;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Services\SupportService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupportTicket extends CreateRecord
{
    protected static string $resource = SupportTicketResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('Ask the committee');
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(SupportService::class)->open(
            auth()->user(),
            $data['type'] instanceof SupportTicketType ? $data['type'] : SupportTicketType::from($data['type']),
            $data['subject'],
            $data['body'],
            $data['attachment'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return SupportTicketResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
