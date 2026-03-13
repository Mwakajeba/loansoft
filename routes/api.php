<?php

use App\Http\Controllers\Api\CustomerAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer API Routes (Mobile / External)
|--------------------------------------------------------------------------
|
| All customer-facing API endpoints served by CustomerAuthController.
| Prefix: /api (applied by Laravel for this file).
|
*/

Route::prefix('customer')->controller(CustomerAuthController::class)->group(function () {
    // Auth
    Route::post('login', 'login');

    // Profile & photo
    Route::post('profile', 'profile');
    Route::post('update-photo', 'updatePhoto');
    Route::post('change-password', 'changePassword');

    // Loans
    Route::post('loans', 'loans');
    Route::post('loan-detail', 'loanDetail');
    Route::post('loan_detail', 'loanDetail'); // alias for loan-detail (in case of proxy/cache)
    Route::get('loan-products', 'loanProducts');

    // Group
    Route::post('group-members', 'groupMembers');

    // Documents (KYC / loan files)
    Route::get('filetypes', 'filetypes');
    Route::post('loan-documents', 'loanDocuments');
    Route::post('upload-loan-document', 'uploadLoanDocument');

    // Contributions & shares
    Route::post('contributions', 'contributions');
    Route::post('contribution-transactions', 'contributionTransactions');
    Route::post('shares', 'shares');
    Route::post('share-transactions', 'shareTransactions');

    // Loan application
    Route::post('submit-loan-application', 'submitLoanApplication');

    // Complaints
    Route::get('complain-categories', 'getComplainCategories');
    Route::post('submit-complain', 'submitComplain');
    Route::post('customer-complains', 'getCustomerComplains');

    // Next of kin
    Route::post('next-of-kin', 'getNextOfKin');

    // Announcements
    Route::post('announcements', 'getAnnouncements');

    // Miamala: customer transactions (receipts – loan repayments, fees, penalty)
    Route::post('transactions', 'customerTransactions');

    // Msaada: company contact (phone, email) from companies table
    Route::get('company-contact', 'companyContact');
});
