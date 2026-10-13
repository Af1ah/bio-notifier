<?php
namespace Tests\Feature;
use App\Models\SiteBridge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class SiteBridgeSecurityTest extends TestCase {
 protected function setUp():void {
  parent::setUp(); config(['database.connections.bridge_test'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],
   'tenancy.database.central_connection'=>'bridge_test','bridge.gateway_key'=>str_repeat('k',64),'bridge.public_url'=>'https://bridge.example.test']);
  Schema::connection('bridge_test')->create('site_bridges',function(Blueprint $t){$t->uuid('id')->primary();$t->string('tenant_id');$t->string('name');$t->string('pairing_hash')->nullable();$t->text('pairing_code')->nullable();$t->timestamp('pairing_expires_at')->nullable();$t->text('public_key')->nullable();$t->string('hardware_id')->nullable();$t->timestamp('last_seen_at')->nullable();$t->timestamp('revoked_at')->nullable();$t->timestamps();});
  Schema::connection('bridge_test')->create('bridge_pairing_requests',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('site_bridge_id');$t->string('hardware_id');$t->text('public_key');$t->string('status')->default('pending');$t->timestamp('expires_at');$t->timestamp('decided_at')->nullable();$t->string('decided_by')->nullable();$t->timestamps();});

 }
 private function identity():array {
  $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);return [$key,openssl_pkey_get_details($key)['key']];
 }
 private function bridge():SiteBridge {$b=new SiteBridge(['name'=>'Test site']);$b->forceFill(['tenant_id'=>'tenant-a'])->save();return $b;}
 public function test_pairing_is_bound_to_one_key_and_supports_power_loss_retry():void {
  [$key,$public]=$this->identity();$b=$this->bridge();$code=$b->issuePairingCode();$body=['code'=>$code,'public_key'=>$public,'hardware_id'=>'test-board'];
  $this->postJson('/api/bridges/pair',$body)->assertOk()->assertJsonPath('bridge_id',$b->id)->assertJsonPath('status','pending');
  $this->postJson('/api/bridges/pair',$body)->assertOk()->assertJsonPath('bridge_id',$b->id)->assertJsonPath('status','pending');
  [, $other]=$this->identity();$body['public_key']=$other;$this->postJson('/api/bridges/pair',$body)->assertForbidden();
 }
 public function test_expired_pairing_code_cannot_claim_identity():void {
  [, $public]=$this->identity();$b=$this->bridge();$code=$b->issuePairingCode();$b->forceFill(['pairing_expires_at'=>now()->subMinute()])->save();
  $this->postJson('/api/bridges/pair',['code'=>$code,'public_key'=>$public,'hardware_id'=>'board'])->assertForbidden();
 }
 public function test_gateway_proof_requires_key_and_respects_revocation():void {
  [$key,$public]=$this->identity();$b=$this->bridge();$b->forceFill(['public_key'=>$public])->save();$nonce=str_repeat('a',64);
  openssl_sign("biomatrix-bridge-v1\n{$b->id}\n{$nonce}",$sig,$key,OPENSSL_ALGO_SHA256);$body=['bridge_id'=>$b->id,'nonce'=>$nonce,'signature'=>base64_encode($sig)];
  $this->postJson('/api/bridge-internal/auth',$body)->assertForbidden();
  $this->withHeaders(['X-Bridge-Gateway-Key'=>str_repeat('k',64)])->postJson('/api/bridge-internal/auth',$body)->assertOk()->assertJsonPath('tenant_id','tenant-a');
  $body['nonce']=str_repeat('b',64);$this->postJson('/api/bridge-internal/auth',$body)->assertForbidden();
  $b->forceFill(['revoked_at'=>now()])->save();$body['nonce']=$nonce;$this->postJson('/api/bridge-internal/auth',$body)->assertForbidden();
 }
 public function test_esp_raw_p256_signature_format_is_accepted():void {
  [$key,$public]=$this->identity();$b=$this->bridge();$b->forceFill(['public_key'=>$public])->save();$nonce=str_repeat('c',64);
  openssl_sign("biomatrix-bridge-v1\n{$b->id}\n{$nonce}",$der,$key,OPENSSL_ALGO_SHA256);
  $rLength=ord($der[3]);$r=substr($der,4,$rLength);$sLength=ord($der[5+$rLength]);$s=substr($der,6+$rLength,$sLength);
  $raw=str_pad(ltrim($r,"\0"),32,"\0",STR_PAD_LEFT).str_pad(ltrim($s,"\0"),32,"\0",STR_PAD_LEFT);
  $this->assertSame(64,strlen($raw));
  $this->withHeaders(['X-Bridge-Gateway-Key'=>str_repeat('k',64)])->postJson('/api/bridge-internal/auth',['bridge_id'=>$b->id,'nonce'=>$nonce,'signature'=>base64_encode($raw)])->assertOk();
 }

 public function test_remote_write_with_unknown_outcome_cannot_be_retried():void {
  $command=new \App\Models\DeviceCommand(['command_content'=>'DOOR_UNLOCK','status'=>'failed','outcome_unknown'=>true]);
  $command->setRelation('device',new \App\Models\Device(['vendor'=>'matrix','connection_mode'=>'remote']));
  $this->expectException(\RuntimeException::class);$command->retry();
 }
 public function test_already_claimed_remote_write_cannot_be_retried():void {
  $command=new \App\Models\DeviceCommand(['command_content'=>'DATA DEL_USER PIN=42','status'=>'sent','outcome_unknown'=>false]);
  $command->setRelation('device',new \App\Models\Device(['vendor'=>'matrix','connection_mode'=>'remote']));
  $this->expectException(\RuntimeException::class);$command->retry();
 }

 private function asSiteAdmin(string $tenantId='tenant-a'):void {
  $this->app->instance(\Stancl\Tenancy\Contracts\Tenant::class,(new \App\Models\Organisation())->forceFill(['id'=>$tenantId]));
  $user=(new \App\Models\User())->forceFill(['id'=>42,'privilege'=>14,'role'=>'admin']);$this->actingAs($user);
 }
 private function submitRequest():array {
  [$key,$public]=$this->identity();$bridge=$this->bridge();$code=$bridge->issuePairingCode();
  $this->postJson('/api/bridges/pair',['code'=>$code,'public_key'=>$public,'hardware_id'=>'approval-test-board'])->assertOk()->assertJsonPath('status','pending');
  return [$bridge,$key,$public,$bridge->pairingRequests()->first()];
 }
 public function test_pending_esp_has_no_access_until_admin_approves():void {
  [$bridge,$key,$public,$request]=$this->submitRequest();$this->assertNull($bridge->fresh()->public_key);
  $nonce=str_repeat('d',64);openssl_sign("biomatrix-bridge-v1\n{$bridge->id}\n{$nonce}",$sig,$key,OPENSSL_ALGO_SHA256);
  $proof=['bridge_id'=>$bridge->id,'nonce'=>$nonce,'signature'=>base64_encode($sig)];
  $this->withHeaders(['X-Bridge-Gateway-Key'=>str_repeat('k',64)])->postJson('/api/bridge-internal/auth',$proof)->assertStatus(409);
  $this->asSiteAdmin();app(\App\Services\Bridge\SiteBridgePairing::class)->decide($request->id,true);
  $this->assertSame('approved',$request->fresh()->status);$this->assertSame($public,$bridge->fresh()->public_key);
  $this->postJson('/api/bridge-internal/auth',$proof)->assertOk();
 }
 public function test_rejection_preserves_history_and_does_not_grant_access():void {
  [$bridge,$key,$public,$request]=$this->submitRequest();$this->asSiteAdmin();app(\App\Services\Bridge\SiteBridgePairing::class)->decide($request->id,false);
  $this->assertSame('rejected',$request->fresh()->status);$this->assertNull($bridge->fresh()->public_key);$this->assertNull($bridge->fresh()->pairing_hash);
 }
 public function test_other_organisation_cannot_approve_request():void {
  [$bridge,$key,$public,$request]=$this->submitRequest();$this->asSiteAdmin('tenant-b');
  $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);app(\App\Services\Bridge\SiteBridgePairing::class)->decide($request->id,true);
 }
 public function test_expired_request_cannot_be_approved():void {
  [$bridge,$key,$public,$request]=$this->submitRequest();$request->update(['expires_at'=>now()->subMinute()]);$this->asSiteAdmin();
  $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(\App\Services\Bridge\SiteBridgePairing::class)->decide($request->id,true);
 }
 public function test_non_admin_cannot_approve_request():void {
  [$bridge,$key,$public,$request]=$this->submitRequest();$this->asSiteAdmin();auth()->user()->forceFill(['privilege'=>1,'role'=>'user']);
  $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(\App\Services\Bridge\SiteBridgePairing::class)->decide($request->id,true);
 }

 public function test_site_cards_show_pending_request_and_approval_actions():void {
  [$bridge,$key,$public,$request]=$this->submitRequest();$this->asSiteAdmin();
  \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('tenant'));
  \Illuminate\Support\Facades\URL::defaults(['tenant'=>'test-site']);
  \Livewire\Livewire::test(\App\Filament\Tenant\Resources\SiteBridgeResource\Pages\ListSiteBridges::class)
   ->assertSee('Connect your sites')->assertSee('approval-test-board')->assertSee('Approve ESP')->assertSee('Reject request')
   ->call('approveRequest',$request->id)->assertSee('Manage devices')->assertDontSee('Approve ESP');
 }
 public function test_manage_page_shows_code_and_keeps_site_settings():void {
  $bridge=$this->bridge();$this->asSiteAdmin();
  \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('tenant'));
  \Illuminate\Support\Facades\URL::defaults(['tenant'=>'test-site']);
  \Livewire\Livewire::test(\App\Filament\Tenant\Resources\SiteBridgeResource\Pages\EditSiteBridge::class,['record'=>$bridge->id])
   ->assertSee('Generate pairing code')->assertSee('Site details')->call('generateCode',$bridge->id)->assertSee('Copy code')->assertSee('https://noti.aflahdev.in');
 }

 public function test_code_survives_reload_and_repeated_generation_until_expiry():void {
  $bridge=$this->bridge();$code=$bridge->issuePairingCode();$expiry=$bridge->fresh()->pairing_expires_at;
  $this->assertSame($code,$bridge->fresh()->activePairingCode());
  $this->assertSame($code,$bridge->fresh()->issuePairingCode());
  $this->assertTrue($expiry->equalTo($bridge->fresh()->pairing_expires_at));
  $cipher=\Illuminate\Support\Facades\DB::connection('bridge_test')->table('site_bridges')->where('id',$bridge->id)->value('pairing_code');
  $this->assertNotSame($code,$cipher);$this->assertStringNotContainsString($code,$cipher);
  $this->assertArrayNotHasKey('pairing_code',$bridge->fresh()->toArray());
  $bridge->forceFill(['pairing_expires_at'=>now()->subSecond()])->save();
  $this->assertNull($bridge->fresh()->activePairingCode());$this->assertNotSame($code,$bridge->fresh()->issuePairingCode());
 }
 public function test_code_is_identical_on_site_cards_and_manage_page_after_remount():void {
  $bridge=$this->bridge();$code=$bridge->issuePairingCode();$this->asSiteAdmin();
  \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('tenant'));
  \Illuminate\Support\Facades\URL::defaults(['tenant'=>'test-site']);
  \Livewire\Livewire::test(\App\Filament\Tenant\Resources\SiteBridgeResource\Pages\ListSiteBridges::class)->assertSee($code);
  \Livewire\Livewire::test(\App\Filament\Tenant\Resources\SiteBridgeResource\Pages\EditSiteBridge::class,['record'=>$bridge->id])->assertSee($code)->call('generateCode',$bridge->id)->assertSee($code);
  $this->assertSame($code,$bridge->fresh()->activePairingCode());
 }

}
