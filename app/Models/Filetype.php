<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Filetype extends Model
{
    use HasFactory,LogsActivity;

    protected $fillable = ['name'];

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_file_types')
                    ->withTimestamps();
    }

    public function loanFiles()
    {
        return $this->hasMany(LoanFile::class, 'file_type_id');
    }

    /**
     * Get the loan products that require this filetype
     */
    public function loanProducts()
    {
        return $this->belongsToMany(LoanProduct::class, 'filetype_loan_product')
            ->withTimestamps();
    }
}
