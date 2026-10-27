@extends('admin.layouts.app')
@section('admin_content')

    {{-- ✅ CSS for Wave Effect --}}
    <style>
        .pulse-icon {
            position: relative;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            overflow: visible;
        }

        .pulse-icon::after {
            content: "";
            position: absolute;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 2px solid currentColor;
            animation: pulseWave 1.5s infinite;
            opacity: 0.6;
        }

        @keyframes pulseWave {
            0% {
                transform: scale(0.6);
                opacity: 0.8;
            }

            70% {
                transform: scale(1.3);
                opacity: 0;
            }

            100% {
                transform: scale(0.6);
                opacity: 0;
            }
        }
    </style>


    <div class="container-xxl flex-grow-1 container-p-y">
        <div class="row g-6 d-flex align-items-center justify-content-center">
            <!-- Card Border Shadow -->
           <div class="col-md-10">
                <div class="row">
                <div class="col-lg-4 col-sm-6">
                <div class="card-border-shadow-primary h-100">
                    <div class="custom-card-body dashboard-card h-100">
                        <div class="d-flex align-items-center mb-2">
                            <div class="avatar me-4">
                                <span class="avatar-initial rounded bg-label-primary">
                                    <i class="icon-base ti tabler-truck icon-28px"></i>
                                </span>
                            </div>
                            <h4 class="mb-0">{{ $clientsCount }}</h4>
                        </div>
                        <p class="mb-1">Total Clients</p>
                        <p class="mb-0">
                            <span class="text-heading fw-medium me-2">{{ $newClientsLast30 }}+</span>
                            <small class="text-body-secondary">new in last 30 days</small>
                        </p>
                    </div>
                </div>


            </div>
            <div class="col-lg-4 col-sm-6">
                <div class="card-border-shadow-warning h-100">
                    <div class="custom-card-body dashboard-card h-100">
                        <div class="d-flex align-items-center mb-2">
                            <div class="avatar me-4">
                                <span class="avatar-initial rounded bg-label-warning">
                                    <i class="icon-base ti tabler-alert-triangle icon-28px"></i>
                                </span>
                            </div>
                            <h4 class="mb-0">{{ $employeesCount }}</h4>
                        </div>
                        <p class="mb-1">Total Employees</p>
                        <p class="mb-0">
                            <span class="text-heading fw-medium me-2">{{ $newEmployeesLast30 }}+</span>
                            <small class="text-body-secondary">new in last 30 days</small>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-sm-6">
                <div class="card-border-shadow-success h-100">
                    <div class="custom-card-body dashboard-card h-100">
                        <div class="d-flex align-items-center mb-2">
                            <div class="avatar me-4 position-relative">
                                <span class="avatar-initial rounded bg-label-success pulse-icon">
                                    <i class="ti ti-login rounded icon-12px"></i>
                                </span>
                            </div>
                            <h4 class="mb-0" id="checkedInCount">0</h4>
                        </div>
                        <p class="mb-1">Today Live Employees</p>
                    </div>
                </div>
            </div>
                </div>
           </div>
        </div>

        {{-- ✅ Real‑time Chart for Today Live Employees --}} 
      

        {{-- ✅ Static Charts for Total Clients and Total Employees --}}
        <div class="row mt-4 d-flex align-items-center justify-content-center">
                <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Employees Overview</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="employeesChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Clients Overview</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="clientsChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        
        </div>
    </div>

  
     {{-- ✅ Include Chart.js if not already loaded --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script>
        // ----- Static Charts for Clients and Employees (update on page reload) -----
        document.addEventListener('DOMContentLoaded', function() {
            // Clients chart
            const ctxClients = document.getElementById('clientsChart').getContext('2d');
            new Chart(ctxClients, {
                type: 'bar',
                data: {
                    labels: ['Total Clients', 'New (30d)'],
                    datasets: [{
                        label: 'Count',
                        data: [{{ $clientsCount }}, {{ $newClientsLast30 }}],
                        backgroundColor: ['rgba(40, 167, 69, 0.7)', 'rgba(255, 193, 7, 0.7)'],
                        borderColor: ['rgb(40, 167, 69)', 'rgb(255, 193, 7)'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0
                            }
                        }
                    }
                }
            });

            // Employees chart
            const ctxEmployees = document.getElementById('employeesChart').getContext('2d');
            new Chart(ctxEmployees, {
                type: 'bar',
                data: {
                    labels: ['Total Employees', 'New (30d)'],
                    datasets: [{
                        label: 'Count',
                        data: [{{ $employeesCount }}, {{ $newEmployeesLast30 }}],
                        backgroundColor: ['rgba(40, 167, 69, 0.7)', 'rgba(255, 193, 7, 0.7)'],
                        borderColor: ['rgb(40, 167, 69)', 'rgb(255, 193, 7)'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0
                            }
                        }
                    }
                }
            });
        });

   
        function loadAttendanceStats() {
            fetch("{{ route('attendance.stats') }}")
                .then(response => response.json())
                .then(data => {
                    const newCount = data.checkedIn;

                    // Update the big number
                    document.getElementById('checkedInCount').innerText = newCount;

                    // Only add a new chart point if the count has changed (or it's the first fetch)
                    if (lastCheckedIn === null || newCount !== lastCheckedIn) {
                        const now = new Date().toLocaleTimeString();
                        timestamps.push(now);
                        counts.push(newCount);

                        // Keep only last maxDataPoints
                        if (timestamps.length > maxDataPoints) {
                            timestamps.shift();
                            counts.shift();
                        }

                        // Update chart
                        liveChart.update();
                    }

                    // Remember this value for next comparison
                    lastCheckedIn = newCount;
                })
                .catch(error => console.error('Error fetching stats:', error));
        }

        // Load immediately and then every second
        loadAttendanceStats();
        setInterval(loadAttendanceStats, 1000);
    </script>
@endsection