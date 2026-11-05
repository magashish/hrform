<?php

namespace App\Enums;

enum InputMode: string
{
    case OnlineForm = 'online_form';
    case Upload = 'upload';
    case Acknowledgement = 'acknowledgement';

    public function label(): string
    {
        return match ($this) {
            self::OnlineForm => 'Online form',
            self::Upload => 'Upload a document',
            self::Acknowledgement => 'Read and acknowledge',
        };
    }
}
