<?php

namespace App\Enums;

enum ComplaintCategory: string
{
    case Conduct = 'conduct';
    case Harassment = 'harassment';
    case PayAndBenefits = 'pay_and_benefits';
    case Safety = 'safety';
    case Assignment = 'assignment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Conduct => 'Workplace conduct',
            self::Harassment => 'Harassment or discrimination',
            self::PayAndBenefits => 'Pay and benefits',
            self::Safety => 'Safety at the post',
            self::Assignment => 'Schedule or assignment',
            self::Other => 'Other',
        };
    }
}
