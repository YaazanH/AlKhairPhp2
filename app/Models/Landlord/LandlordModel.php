<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

abstract class LandlordModel extends Model
{
    protected $connection = 'landlord';
}
