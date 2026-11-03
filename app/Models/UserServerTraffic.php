<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserServerTraffic extends Model
{
    protected $table = 'v2_user_server_traffic';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
}
