<?php

namespace App\Exports;

use App\Models\Region;
use App\Models\District;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Carbon\Carbon;

class CustomerBulkUploadSampleExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents
{
    public function array(): array
    {
        $data = [];
        $firstNames = ['John', 'Jane', 'Mary', 'Peter', 'Sarah', 'Michael', 'Elizabeth', 'David', 'Anna', 'James', 'Emily', 'Robert', 'Linda', 'William', 'Patricia', 'Richard', 'Jennifer', 'Joseph', 'Maria', 'Thomas', 'Susan', 'Charles', 'Margaret', 'Daniel', 'Dorothy', 'Matthew', 'Lisa', 'Anthony', 'Nancy', 'Mark', 'Karen', 'Donald', 'Betty', 'Steven', 'Helen', 'Paul', 'Sandra', 'Andrew', 'Donna', 'Joshua', 'Carol', 'Kenneth', 'Ruth', 'Kevin', 'Sharon', 'Brian', 'Michelle', 'George', 'Laura', 'Edward', 'Sarah', 'Ronald', 'Kimberly', 'Timothy', 'Deborah', 'Jason', 'Jessica', 'Jeffrey', 'Shirley', 'Ryan', 'Cynthia', 'Jacob', 'Angela', 'Gary', 'Melissa', 'Nicholas', 'Brenda', 'Eric', 'Emma', 'Jonathan', 'Ashley', 'Stephen', 'Amy', 'Larry', 'Anna', 'Justin', 'Rebecca', 'Scott', 'Virginia', 'Brandon', 'Kathleen', 'Benjamin', 'Pamela', 'Samuel', 'Martha', 'Frank', 'Debra', 'Gregory', 'Amanda', 'Raymond', 'Stephanie', 'Alexander', 'Carolyn', 'Patrick', 'Christine', 'Jack', 'Marie', 'Dennis', 'Janet', 'Jerry', 'Catherine', 'Tyler', 'Frances', 'Aaron', 'Ann'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson', 'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores', 'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell', 'Carter', 'Roberts', 'Gomez', 'Phillips', 'Evans', 'Turner', 'Diaz', 'Parker', 'Cruz', 'Edwards', 'Collins', 'Reyes', 'Stewart', 'Morris', 'Morales', 'Murphy', 'Cook', 'Rogers', 'Gutierrez', 'Ortiz', 'Morgan', 'Cooper', 'Peterson', 'Bailey', 'Reed', 'Kelly', 'Howard', 'Ramos', 'Kim', 'Cox', 'Ward', 'Richardson', 'Watson', 'Brooks', 'Chavez', 'Wood', 'James', 'Bennett', 'Gray', 'Mendoza', 'Ruiz', 'Hughes', 'Price', 'Alvarez', 'Castillo', 'Sanders', 'Patel', 'Myers', 'Long', 'Ross', 'Foster', 'Jimenez'];

        // Generate 100 sample customers
        for ($i = 1; $i <= 100; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $sex = ['M', 'F'][array_rand(['M', 'F'])];
            $dob = Carbon::now()->subYears(rand(18, 65))->subDays(rand(0, 365))->format('Y-m-d');
            $phone1 = '255' . rand(700000000, 799999999);
            $phone2 = rand(0, 1) ? '255' . rand(700000000, 799999999) : '';
            
            $data[] = [
                $firstName . ' ' . $lastName,  // name
                $phone1,                        // phone1
                $phone2,                        // phone2
                $dob,                           // dob
                $sex,                           // sex (will have dropdown)
                '',                             // region_id (will have dropdown)
                '',                             // district_id (will have dropdown)
                ['Teacher', 'Nurse', 'Farmer', 'Business Owner', 'Engineer', 'Doctor', 'Lawyer', 'Accountant', 'Driver', 'Mechanic'][array_rand(['Teacher', 'Nurse', 'Farmer', 'Business Owner', 'Engineer', 'Doctor', 'Lawyer', 'Accountant', 'Driver', 'Mechanic'])], // work
                'Sample Work Address ' . $i,   // workaddress
                ['National ID', 'License', 'Voter Registration', 'Other'][array_rand(['National ID', 'License', 'Voter Registration', 'Other'])], // idtype
                'ID' . str_pad($i, 8, '0', STR_PAD_LEFT), // idnumber
                ['Spouse', 'Parent', 'Sibling', ''][array_rand(['Spouse', 'Parent', 'Sibling', ''])], // relation
                'Sample customer ' . $i,       // description
                'Borrower',                    // category (will have dropdown)
            ];
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'name',
            'phone1',
            'phone2',
            'dob',
            'sex',
            'region_id',
            'district_id',
            'work',
            'workaddress',
            'idtype',
            'idnumber',
            'relation',
            'description',
            'category',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF4472C4']
                ],
                'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 25,  // name
            'B' => 15,  // phone1
            'C' => 15,  // phone2
            'D' => 12,  // dob
            'E' => 10,  // sex
            'F' => 20,  // region_id
            'G' => 20,  // district_id
            'H' => 20,  // work
            'I' => 30,  // workaddress
            'J' => 15,  // idtype
            'K' => 15,  // idnumber
            'L' => 15,  // relation
            'M' => 30,  // description
            'N' => 18,  // category
        ];
    }

    public function title(): string
    {
        return 'Customers';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $spreadsheet = $sheet->getParent();
                
                // Get regions and districts for dropdowns
                $regions = Region::all();
                $regionNames = $regions->pluck('name')->toArray();
                $districts = District::all();
                $districtNames = $districts->pluck('name')->toArray();
                
                // Create helper sheet for dropdown data (hidden)
                $helperSheet = $spreadsheet->createSheet();
                $helperSheet->setTitle('Data');
                $helperSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
                
                // Write regions to helper sheet (Column A, starting from row 1)
                $helperSheet->setCellValue('A1', 'Regions');
                foreach ($regionNames as $index => $regionName) {
                    $helperSheet->setCellValue('A' . ($index + 2), $regionName);
                }
                
                // Write districts to helper sheet (Column B, starting from row 1)
                $helperSheet->setCellValue('B1', 'Districts');
                foreach ($districtNames as $index => $districtName) {
                    $helperSheet->setCellValue('B' . ($index + 2), $districtName);
                }
                
                // Headers are at row 1 (from WithHeadings), data starts at row 2
                // Insert instruction rows AFTER headers (rows 2-5)
                $sheet->insertNewRowBefore(2, 4);
                
                // Instructions (rows 2-5, after header row 1)
                $sheet->setCellValue('A2', 'CUSTOMER BULK UPLOAD TEMPLATE');
                $sheet->mergeCells('A2:N2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A2')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF4472C4');
                $sheet->getStyle('A2')->getFont()->getColor()->setARGB('FFFFFFFF');
                
                $sheet->setCellValue('A3', 'Instructions:');
                $sheet->getStyle('A3')->getFont()->setBold(true);
                $sheet->setCellValue('A4', '1. Fill in all required fields (name, phone1, dob, sex)');
                $sheet->setCellValue('A5', '2. Use dropdowns for Sex (M/F), ID Type, Region, District, and Category (Borrower/Guarantor)');
                $sheet->setCellValue('A6', '3. Keep the header row (row 1) - DO NOT DELETE IT. You can delete instruction rows (2-6) and sample data.');
                $sheet->mergeCells('A4:N4');
                $sheet->mergeCells('A5:N5');
                $sheet->mergeCells('A6:N6');
                
                // Header row is at row 1, data starts at row 7 (after 1 header + 5 instruction rows)
                $dataStartRow = 7;
                $dataEndRow = 106; // 100 customers + header row
                
                // Add sex dropdown (Column E)
                $sexValidation = new DataValidation();
                $sexValidation->setType(DataValidation::TYPE_LIST);
                $sexValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $sexValidation->setAllowBlank(false);
                $sexValidation->setShowInputMessage(true);
                $sexValidation->setShowErrorMessage(true);
                $sexValidation->setShowDropDown(true);
                $sexValidation->setFormula1('"M,F"');
                
                // Add region dropdown (Column F) using helper sheet
                $regionValidation = new DataValidation();
                $regionValidation->setType(DataValidation::TYPE_LIST);
                $regionValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $regionValidation->setAllowBlank(true);
                $regionValidation->setShowInputMessage(true);
                $regionValidation->setShowErrorMessage(true);
                $regionValidation->setShowDropDown(true);
                $regionValidation->setFormula1('Data!$A$2:$A$' . (count($regionNames) + 1));
                
                // Add district dropdown (Column G) using helper sheet
                $districtValidation = new DataValidation();
                $districtValidation->setType(DataValidation::TYPE_LIST);
                $districtValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $districtValidation->setAllowBlank(true);
                $districtValidation->setShowInputMessage(true);
                $districtValidation->setShowErrorMessage(true);
                $districtValidation->setShowDropDown(true);
                $districtValidation->setFormula1('Data!$B$2:$B$' . (count($districtNames) + 1));

                // Add idtype dropdown (Column J)
                $idTypeValidation = new DataValidation();
                $idTypeValidation->setType(DataValidation::TYPE_LIST);
                $idTypeValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $idTypeValidation->setAllowBlank(true);
                $idTypeValidation->setShowInputMessage(true);
                $idTypeValidation->setShowErrorMessage(true);
                $idTypeValidation->setShowDropDown(true);
                $idTypeValidation->setFormula1('"National ID,License,Voter Registration,Other"');

                // Add category dropdown (Column N)
                $categoryValidation = new DataValidation();
                $categoryValidation->setType(DataValidation::TYPE_LIST);
                $categoryValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $categoryValidation->setAllowBlank(false);
                $categoryValidation->setShowInputMessage(true);
                $categoryValidation->setShowErrorMessage(true);
                $categoryValidation->setShowDropDown(true);
                $categoryValidation->setFormula1('"Borrower,Guarantor"');
                
                // Apply validations to all data rows
                for ($row = $dataStartRow; $row <= $dataEndRow; $row++) {
                    // Sex dropdown
                    $sheet->getCell('E' . $row)->setDataValidation(clone $sexValidation);
                    // Region dropdown
                    $sheet->getCell('F' . $row)->setDataValidation(clone $regionValidation);
                    // District dropdown
                    $sheet->getCell('G' . $row)->setDataValidation(clone $districtValidation);
                    // ID Type dropdown
                    $sheet->getCell('J' . $row)->setDataValidation(clone $idTypeValidation);
                    // Category dropdown
                    $sheet->getCell('N' . $row)->setDataValidation(clone $categoryValidation);
                }
                
                // Add borders to header row
                $sheet->getStyle('A6:N6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}
