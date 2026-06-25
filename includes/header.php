<?php
/**
 * Shared HTML Head Layout Template
 * 
 * Sets page titles, includes Bootstrap 5 CSS/JS, Tailwind CSS, Google Fonts, and custom tailwind theme overrides.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . " - MedCore" : "MedCore HMS"; ?></title>
    
    <!-- Bootstrap 5 CSS (Used for offcanvas, tabs, and modals structure) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts: Inter (Sans) and Lora (Serif) -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet"/>

    <!-- Custom Tailwind Configuration to match index.html exactly -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        hms: {
                            panel: '#E4EBF4',
                            bg: '#F7F9FC',
                            border: '#E5EAF0',
                            accent: '#4F7CAC',
                            accentDim: '#3D6490',
                            accentDark: '#335680',
                            dark: '#1F2937',
                            mid: '#4B5563',
                            muted: '#9CA3AF',
                            error: '#DC2626'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        serif: ['Lora', 'serif'],
                    }
                }
            }
        }
    </script>

    <!-- Central brand stylesheet for manual overrides and print logic -->
    <link href="assets/css/style.css" rel="stylesheet">
