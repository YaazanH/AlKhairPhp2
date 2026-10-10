<?php

namespace App\Models\Landlord;

class PlatformLandingEnquiry extends LandlordModel
{
    protected $fillable = ['uuid', 'name', 'organisation_name', 'email', 'phone', 'message', 'locale', 'ip_address'];
}
