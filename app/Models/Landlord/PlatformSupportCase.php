<?php
namespace App\Models\Landlord;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PlatformSupportCase extends LandlordModel {protected $fillable=['tenant_id','tenant_support_request_id','type','status','subject','message','forwarded_at'];protected function casts():array{return ['forwarded_at'=>'datetime'];}public function tenant():BelongsTo{return $this->belongsTo(Tenant::class);}}
