<?php
namespace App\Http\Controllers;
use App\Models\TenantSupportRequest;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\View\View;
class TenantSupportRequestController extends Controller { public function index(Request $request):View{return view('support.index',['requests'=>TenantSupportRequest::query()->where('submitted_by_user_id',$request->user()->id)->latest()->get()]);} public function store(Request $request):RedirectResponse{$data=$request->validate(['type'=>['required','in:problem,suggestion'],'subject'=>['required','string','max:180'],'message'=>['required','string','max:5000']]);TenantSupportRequest::query()->create($data+['submitted_by_user_id'=>$request->user()->id]);return back()->with('status','Your request was submitted.');}}
