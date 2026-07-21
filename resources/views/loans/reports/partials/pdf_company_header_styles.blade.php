{{-- Shared PDF company header styles (logo left, company info from center) --}}
.pdf-header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6px;
}

.pdf-header-table td {
    vertical-align: middle;
    border: none;
    padding: 0;
}

.pdf-logo-cell {
    width: 26%;
    text-align: left;
}

.pdf-company-info-cell {
    width: 48%;
    text-align: left;
    padding-left: 8px;
}

.pdf-company-logo {
    max-height: 150px;
    max-width: 220px;
    width: auto;
    height: auto;
    object-fit: contain;
}

.pdf-company-name {
    font-size: 17px;
    font-weight: bold;
    color: #1e40af;
}

.pdf-company-details {
    font-size: 10px;
    line-height: 1.45;
    color: #000;
}

.pdf-report-title {
    font-weight: bold;
    text-align: center;
    font-size: 16px;
    margin: 6px 0;
    color: #1e40af;
    text-transform: uppercase;
}

.pdf-title-rule {
    border: none;
    border-top: 2px solid #3b82f6;
    margin: 8px 0 12px;
}

.pdf-report-info {
    font-size: 9px;
    color: #000;
    margin: 2px 0 8px;
    text-align: center;
}
