<?php

namespace Modules\SupportTickets\Enums;

enum TicketCategory: string
{
    case GeneralInquiry = 'general_inquiry';
    case BookingIssue = 'booking_issue';
    case PaymentIssue = 'payment_issue';
    case Technical = 'technical';
    case ConsultantComplaint = 'consultant_complaint';
}
