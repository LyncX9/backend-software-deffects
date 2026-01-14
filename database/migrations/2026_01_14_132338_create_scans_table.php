<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('file_path')->nullable();
            $table->json('metrics')->nullable();
            $table->json('result')->nullable();
            $table->string('status')->default('pending');
            $table->float('defect_probability')->default(0);
            $table->string('risk_level')->default('low');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
