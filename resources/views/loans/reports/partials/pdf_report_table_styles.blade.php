{{-- Shared data table + footer styles for loan report PDFs --}}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
}

th, td {
    border: 1px solid #000;
    padding: 3px 2px;
    text-align: left;
    font-size: 8px;
    color: #000;
}

th {
    background-color: #000;
    color: #fff;
    font-weight: bold;
    text-align: center;
}

.text-right { text-align: right; }
.text-center { text-align: center; }
.total-row { background-color: #f0f0f0; font-weight: bold; }

.footer {
    margin-top: 15px;
    padding-top: 8px;
    border-top: 1px solid #000;
    text-align: center;
    font-size: 8px;
    color: #000;
}

.footer p { margin: 2px 0; }

.digital-signature {
    margin-top: 5px;
    font-size: 7px;
    color: #000;
    font-style: italic;
}

.pdf-footer-note {
    margin-top: 8px;
    font-size: 9px;
    text-align: center;
    color: #555;
}
