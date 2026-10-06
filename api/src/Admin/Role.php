<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Provider = 'provider';
}
