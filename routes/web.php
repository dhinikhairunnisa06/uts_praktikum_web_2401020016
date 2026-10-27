<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

Route::get('/inventaris', function (Request $request) {
    $kataKunci = trim((string) $request->query('q', ''));
    $ruangId = $request->query('ruang_id', '');

    $statusUsiaAset = function (int $tahun): string {
        if ($tahun >= 2023) {
            return 'Baru';
        } else {
            return 'Lama';
        }
    };

    try {
        $pdo = DB::connection()->getPdo();

        $sql = 'SELECT i.id, i.kode_barang, i.nama_barang, i.nomor_aset,
                       i.tahun_perolehan, i.ruang_id, r.nama_ruang
                FROM inventaris AS i
                JOIN ruang AS r
                    ON r.id = i.ruang_id
                WHERE 1 = 1';
        $parameter = [];

        if ($kataKunci !== '') {
            $sql .= ' AND (i.kode_barang LIKE :kata_kunci_kode
                        OR i.nama_barang LIKE :kata_kunci_nama
                        OR i.nomor_aset LIKE :kata_kunci_aset)';
            $nilaiPencarian = '%' . $kataKunci . '%';
            $parameter['kata_kunci_kode'] = $nilaiPencarian;
            $parameter['kata_kunci_nama'] = $nilaiPencarian;
            $parameter['kata_kunci_aset'] = $nilaiPencarian;
        }

        if ($ruangId !== '') {
            $sql .= ' AND i.ruang_id = :ruang_id';
            $parameter['ruang_id'] = (int) $ruangId;
        }

        $sql .= ' ORDER BY i.kode_barang';

        $ambilInventaris = $pdo->prepare($sql);
        $ambilInventaris->execute($parameter);
        $daftarInventaris = $ambilInventaris->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($daftarInventaris as $indeks => $barang) {
            $daftarInventaris[$indeks]['status_usia'] = $statusUsiaAset(
                (int) $barang['tahun_perolehan']
            );
        }

        $ambilRuang = $pdo->prepare(
            'SELECT id, nama_ruang
             FROM ruang
             ORDER BY nama_ruang'
        );
        $ambilRuang->execute();
        $daftarRuang = $ambilRuang->fetchAll(\PDO::FETCH_ASSOC);

        return view('inventaris', compact(
            'daftarInventaris',
            'daftarRuang',
            'kataKunci',
            'ruangId'
        ));
    } catch (\Throwable $error) {
        report($error);

        return response(
            'Data tidak dapat dibaca. Periksa koneksi dan query.',
            500
        );
    }
})->name('inventaris.index');

Route::post('/inventaris', function (Request $request) {
    $dataBersih = [
        'kode_barang' => strip_tags(trim((string) $request->input('kode_barang'))),
        'nama_barang' => strip_tags(trim((string) $request->input('nama_barang'))),
        'nomor_aset' => strip_tags(trim((string) $request->input('nomor_aset'))),
        'tahun_perolehan' => trim((string) $request->input('tahun_perolehan')),
        'ruang_id' => trim((string) $request->input('ruang_id')),
    ];

    $validator = Validator::make($dataBersih, [
        'kode_barang' => ['required', 'min:10', 'max:10', 'alpha_num'],
        'nama_barang' => ['required', 'min:3', 'max:100'],
        'nomor_aset' => ['required', 'min:5', 'max:30'],
        'tahun_perolehan' => ['required', 'integer', 'min:2000', 'max:2026'],
        'ruang_id' => ['required', 'integer'],
    ], [
        'kode_barang.required' => 'Kode barang wajib diisi.',
        'kode_barang.min' => 'Kode barang harus tepat 10 karakter.',
        'kode_barang.max' => 'Kode barang harus tepat 10 karakter.',
        'kode_barang.alpha_num' => 'Kode barang hanya boleh huruf dan angka.',
        'nama_barang.required' => 'Nama barang wajib diisi.',
        'nama_barang.min' => 'Nama barang minimal 3 karakter.',
        'nama_barang.max' => 'Nama barang maksimal 100 karakter.',
        'nomor_aset.required' => 'Nomor aset wajib diisi.',
        'nomor_aset.min' => 'Nomor aset minimal 5 karakter.',
        'nomor_aset.max' => 'Nomor aset maksimal 30 karakter.',
        'tahun_perolehan.required' => 'Tahun perolehan wajib diisi.',
        'tahun_perolehan.integer' => 'Tahun perolehan harus berupa angka.',
        'tahun_perolehan.min' => 'Tahun perolehan minimal 2000.',
        'tahun_perolehan.max' => 'Tahun perolehan maksimal 2026.',
        'ruang_id.required' => 'Ruang wajib dipilih.',
        'ruang_id.integer' => 'Ruang tidak valid.',
    ]);

    if ($validator->fails()) {
        return back()->withErrors($validator)->withInput();
    }

    $data = $validator->validated();

    try {
        $pdo = DB::connection()->getPdo();

        $cekRuang = $pdo->prepare(
            'SELECT COUNT(*) FROM ruang WHERE id = :id'
        );
        $cekRuang->execute(['id' => $data['ruang_id']]);

        if ((int) $cekRuang->fetchColumn() === 0) {
            return back()->withInput()->with(
                'gagal',
                'Ruang yang dipilih tidak tersedia.'
            );
        }

        $cekDuplikat = $pdo->prepare(
            'SELECT COUNT(*) FROM inventaris
             WHERE kode_barang = :kode_barang
                OR nomor_aset = :nomor_aset'
        );
        $cekDuplikat->execute([
            'kode_barang' => $data['kode_barang'],
            'nomor_aset' => $data['nomor_aset'],
        ]);

        if ((int) $cekDuplikat->fetchColumn() > 0) {
            return back()->withInput()->with(
                'gagal',
                'Kode barang atau nomor aset sudah digunakan.'
            );
        }

        $simpan = $pdo->prepare(
            'INSERT INTO inventaris
                (kode_barang, nama_barang, nomor_aset,
                 tahun_perolehan, ruang_id)
             VALUES
                (:kode_barang, :nama_barang, :nomor_aset,
                 :tahun_perolehan, :ruang_id)'
        );
        $simpan->execute([
            'kode_barang' => $data['kode_barang'],
            'nama_barang' => $data['nama_barang'],
            'nomor_aset' => $data['nomor_aset'],
            'tahun_perolehan' => $data['tahun_perolehan'],
            'ruang_id' => $data['ruang_id'],
        ]);

        return redirect()->route('inventaris.index')->with(
            'berhasil',
            'Data inventaris berhasil ditambahkan.'
        );
    } catch (\Throwable $error) {
        report($error);

        return back()->withInput()->with(
            'gagal',
            'Data gagal disimpan. Periksa koneksi dan data.'
        );
    }
})->name('inventaris.store');

Route::get('/inventaris/{id}/edit', function (int $id) {
    try {
        $pdo = DB::connection()->getPdo();

        $ambilBarang = $pdo->prepare(
            'SELECT id, kode_barang, nama_barang, nomor_aset,
                    tahun_perolehan, ruang_id
             FROM inventaris WHERE id = :id'
        );
        $ambilBarang->execute(['id' => $id]);
        $barang = $ambilBarang->fetch(\PDO::FETCH_ASSOC);

        if (!$barang) {
            return redirect()->route('inventaris.index')->with(
                'gagal',
                'Data inventaris tidak ditemukan.'
            );
        }

        $ambilRuang = $pdo->prepare(
            'SELECT id, nama_ruang FROM ruang ORDER BY nama_ruang'
        );
        $ambilRuang->execute();
        $daftarRuang = $ambilRuang->fetchAll(\PDO::FETCH_ASSOC);

        return view('inventaris-edit', compact('barang', 'daftarRuang'));
    } catch (\Throwable $error) {
        report($error);

        return redirect()->route('inventaris.index')->with(
            'gagal',
            'Data gagal dibuka untuk diubah.'
        );
    }
})->name('inventaris.edit');

Route::put('/inventaris/{id}', function (Request $request, int $id) {
    $dataBersih = [
        'kode_barang' => strip_tags(trim((string) $request->input('kode_barang'))),
        'nama_barang' => strip_tags(trim((string) $request->input('nama_barang'))),
        'nomor_aset' => strip_tags(trim((string) $request->input('nomor_aset'))),
        'tahun_perolehan' => trim((string) $request->input('tahun_perolehan')),
        'ruang_id' => trim((string) $request->input('ruang_id')),
    ];

    $validator = Validator::make($dataBersih, [
        'kode_barang' => ['required', 'min:10', 'max:10', 'alpha_num'],
        'nama_barang' => ['required', 'min:3', 'max:100'],
        'nomor_aset' => ['required', 'min:5', 'max:30'],
        'tahun_perolehan' => ['required', 'integer', 'min:2000', 'max:2026'],
        'ruang_id' => ['required', 'integer'],
    ], [
        'kode_barang.required' => 'Kode barang wajib diisi.',
        'kode_barang.min' => 'Kode barang harus tepat 10 karakter.',
        'kode_barang.max' => 'Kode barang harus tepat 10 karakter.',
        'kode_barang.alpha_num' => 'Kode barang hanya boleh huruf dan angka.',
        'nama_barang.required' => 'Nama barang wajib diisi.',
        'nama_barang.min' => 'Nama barang minimal 3 karakter.',
        'nama_barang.max' => 'Nama barang maksimal 100 karakter.',
        'nomor_aset.required' => 'Nomor aset wajib diisi.',
        'nomor_aset.min' => 'Nomor aset minimal 5 karakter.',
        'nomor_aset.max' => 'Nomor aset maksimal 30 karakter.',
        'tahun_perolehan.required' => 'Tahun perolehan wajib diisi.',
        'tahun_perolehan.integer' => 'Tahun perolehan harus berupa angka.',
        'tahun_perolehan.min' => 'Tahun perolehan minimal 2000.',
        'tahun_perolehan.max' => 'Tahun perolehan maksimal 2026.',
        'ruang_id.required' => 'Ruang wajib dipilih.',
        'ruang_id.integer' => 'Ruang tidak valid.',
    ]);

    if ($validator->fails()) {
        return back()->withErrors($validator)->withInput();
    }

    $data = $validator->validated();

    try {
        $pdo = DB::connection()->getPdo();

        $cekRuang = $pdo->prepare(
            'SELECT COUNT(*) FROM ruang WHERE id = :id'
        );
        $cekRuang->execute(['id' => $data['ruang_id']]);

        if ((int) $cekRuang->fetchColumn() === 0) {
            return back()->withInput()->with(
                'gagal',
                'Ruang yang dipilih tidak tersedia.'
            );
        }

        $cek = $pdo->prepare(
            'SELECT COUNT(*) FROM inventaris
             WHERE (kode_barang = :kode_barang
                OR nomor_aset = :nomor_aset)
               AND id <> :id'
        );
        $cek->execute([
            'kode_barang' => $data['kode_barang'],
            'nomor_aset' => $data['nomor_aset'],
            'id' => $id,
        ]);

        if ((int) $cek->fetchColumn() > 0) {
            return back()->withInput()->with(
                'gagal',
                'Kode barang atau nomor aset sudah digunakan data lain.'
            );
        }

        $ubah = $pdo->prepare(
            'UPDATE inventaris
             SET kode_barang = :kode_barang,
                 nama_barang = :nama_barang,
                 nomor_aset = :nomor_aset,
                 tahun_perolehan = :tahun_perolehan,
                 ruang_id = :ruang_id
             WHERE id = :id'
        );
        $ubah->execute([
            'kode_barang' => $data['kode_barang'],
            'nama_barang' => $data['nama_barang'],
            'nomor_aset' => $data['nomor_aset'],
            'tahun_perolehan' => $data['tahun_perolehan'],
            'ruang_id' => $data['ruang_id'],
            'id' => $id,
        ]);

        return redirect()->route('inventaris.index')->with(
            'berhasil',
            'Data inventaris berhasil diperbarui.'
        );
    } catch (\Throwable $error) {
        report($error);

        return back()->withInput()->with(
            'gagal',
            'Data gagal diperbarui. Periksa koneksi dan data.'
        );
    }
})->name('inventaris.update');

Route::delete('/inventaris/{id}', function (int $id) {
    try {
        $pdo = DB::connection()->getPdo();
        $hapus = $pdo->prepare(
            'DELETE FROM inventaris WHERE id = :id'
        );
        $hapus->execute(['id' => $id]);

        if ($hapus->rowCount() === 0) {
            return redirect()->route('inventaris.index')->with(
                'gagal',
                'Data inventaris tidak ditemukan.'
            );
        }

        return redirect()->route('inventaris.index')->with(
            'berhasil',
            'Data inventaris berhasil dihapus.'
        );
    } catch (\Throwable $error) {
        report($error);

        return redirect()->route('inventaris.index')->with(
            'gagal',
            'Data gagal dihapus. Periksa relasi atau koneksi.'
        );
    }
})->name('inventaris.destroy');