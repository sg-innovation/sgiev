<?php

namespace App\Http\Controllers;

use App\Models\CompanyDocument;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authorised download of private KYC documents (platform admins and the company's own owners/finance). */
class CompanyDocumentController extends Controller
{
    public function show(Request $request, CompanyDocument $document): Response
    {
        $user = $request->user();
        $allowed = Tenancy::isPlatformAdmin($user)
            || ($user->company_id === $document->company_id && $user->can('company.view'));
        abort_unless($allowed, 403);

        activity()->performedOn($document)->causedBy($user)->log('kyc document viewed');

        return response($document->contents(), 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
