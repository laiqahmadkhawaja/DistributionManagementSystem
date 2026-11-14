/**
 * Enterprise Distribution Management System (DMS)
 * Main Application Script (Vanilla JS, Strictly No Modals)
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Setup Inline Confirmation Handlers (Strict No-Modal / No Alert / No Confirm)
    document.querySelectorAll('[data-confirm-trigger]').forEach(function (button) {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-confirm-target');
            const panel = document.getElementById(targetId);
            if (panel) {
                panel.classList.remove('d-none');
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });

    document.querySelectorAll('[data-confirm-cancel]').forEach(function (button) {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-confirm-target');
            const panel = document.getElementById(targetId);
            if (panel) {
                panel.classList.add('d-none');
            }
        });
    });

    // 2. Auto-hide alert banners after 6 seconds
    document.querySelectorAll('.alert-auto-dismiss').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 400);
        }, 6000);
    });

    // 3. Highlight current mobile bottom nav link
    const currentPath = window.location.pathname;
    document.querySelectorAll('.mobile-nav-item').forEach(function (item) {
        const href = item.getAttribute('href');
        if (href && currentPath.endsWith(href)) {
            item.classList.add('active');
        }
    });

    // 4. Quick Notification Toast Creator (DOM-based, no modal backdrop)
    window.showDmsToast = function (message, type = 'info') {
        let container = document.getElementById('dms-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'dms-toast-container';
            container.style.position = 'fixed';
            container.style.top = '16px';
            container.style.right = '16px';
            container.style.zIndex = '9999';
            container.style.maxWidth = '340px';
            document.body.appendChild(container);
        }

        const alert = document.createElement('div');
        const bgClass = type === 'success' ? 'bg-success text-white' : (type === 'danger' ? 'bg-danger text-white' : 'bg-primary text-white');
        alert.className = `alert ${bgClass} shadow-sm border-0 d-flex align-items-center justify-content-between p-3 mb-2 rounded-3`;
        alert.innerHTML = `
            <div>${message}</div>
            <button type="button" class="btn-close btn-close-white ms-2" aria-label="Close"></button>
        `;

        alert.querySelector('.btn-close').addEventListener('click', function () {
            alert.remove();
        });

        container.appendChild(alert);

        setTimeout(function () {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 400);
        }, 4000);
    };

    // 5. WhatsApp Direct Share Helper
    window.shareOnWhatsApp = function (phone, text) {
        let cleanPhone = (phone || '').replace(/[^0-9]/g, '');
        if (cleanPhone.startsWith('03') && cleanPhone.length === 11) {
            cleanPhone = '92' + cleanPhone.substring(1);
        }
        const encodedText = encodeURIComponent(text || '');
        let url = cleanPhone ? `https://api.whatsapp.com/send?phone=${cleanPhone}&text=${encodedText}` : `https://api.whatsapp.com/send?text=${encodedText}`;
        window.open(url, '_blank');
    };

    // 6. html2canvas Image Capture & Direct Share / Download
    window.captureAndShareElement = function (elementId, filename = 'document') {
        const el = document.getElementById(elementId);
        if (!el) {
            window.showDmsToast('Element not found for image capture', 'danger');
            return;
        }

        if (typeof html2canvas === 'undefined') {
            window.showDmsToast('Image capture library not ready. Please print as PDF instead.', 'danger');
            return;
        }

        window.showDmsToast('Generating high-resolution image...', 'info');

        html2canvas(el, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff',
            logging: false
        }).then(function (canvas) {
            canvas.toBlob(function (blob) {
                if (!blob) {
                    window.showDmsToast('Failed to generate image blob.', 'danger');
                    return;
                }

                const file = new File([blob], `${filename}.png`, { type: 'image/png' });

                // If Web Share API supports file sharing (mobile / Android WebView), trigger share
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({
                        files: [file],
                        title: filename,
                        text: `${filename} from Falcon FMCG Distribution System`
                    }).then(() => {
                        window.showDmsToast('Shared successfully!', 'success');
                    }).catch((err) => {
                        // User cancelled or share failed, fallback to file download
                        triggerDownload(canvas, filename);
                    });
                } else {
                    // Desktop / browser download fallback
                    triggerDownload(canvas, filename);
                }
            }, 'image/png');
        }).catch(function (err) {
            console.error('html2canvas error:', err);
            window.showDmsToast('Image generation failed. Please use Print as PDF.', 'danger');
        });

        function triggerDownload(canvas, fname) {
            const link = document.createElement('a');
            link.download = `${fname}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
            window.showDmsToast('Image downloaded! You can now send it directly on WhatsApp.', 'success');
        }
    };

    // 7. Excel / CSV Export Utility (Client-Side UTF-8 BOM for Microsoft Excel)
    window.exportTableToCSV = function (tableId, filename = 'report') {
        const table = document.getElementById(tableId);
        if (!table) {
            window.showDmsToast('Report table not found.', 'danger');
            return;
        }

        let csv = [];
        const rows = table.querySelectorAll('tr');

        for (let i = 0; i < rows.length; i++) {
            const row = [];
            const cols = rows[i].querySelectorAll('td, th');

            for (let j = 0; j < cols.length; j++) {
                // Remove inner line breaks and clean whitespace
                let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
                // Escape double quotes
                data = data.replace(/"/g, '""');
                row.push('"' + data + '"');
            }
            if (row.length > 0) {
                csv.push(row.join(','));
            }
        }

        const csvString = '\uFEFF' + csv.join('\r\n');
        const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `${filename}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        window.showDmsToast('Exported to Excel CSV successfully!', 'success');
    };
});
