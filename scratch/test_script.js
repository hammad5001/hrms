

        const API_BASE = 'attendance/';
        let allData = [];
        let rawAllData = [];
        let currentYear = 2026;
        let currentMonth = 7;
        let daysInMonth = 31;
        let workingDaysCount = 0;
        let elapsedWorkingDaysCount = 0;
        let leaves = {};
        let attendanceTrendChart = null;
        let departmentChart = null;
        let deptPayrollChart = null;
        let salaryBreakdownChart = null;
        const PAYROLL_API = 'api/payroll_api.php';
        let currentPayrollSearchTerm = '';
        let activeView = 'overview';
        let financeUsersList = [];
        let selectedPayrollTeam = '';
        let selectedPayrollQuery = '';

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        // ===== SIDEBAR NAVIGATION SWITCHER =====
        function switchView(view) {
            activeView = view;
            
            // Sidebar buttons
            document.querySelectorAll('.sidebar-nav .nav-item').forEach(b => b.classList.remove('active'));
            const activeNav = document.querySelector(`.sidebar-nav .nav-item[data-view="${view}"]`);
            if (activeNav) activeNav.classList.add('active');
            
            // View title
            const viewTitles = {
                overview: 'Overview Hub',
                attendance: 'Monthly Attendance Grid',
                users: 'Users Settings',
                'payroll-sheet': 'Complete Payroll Sheet',
                'bank-format': 'Bank Format Payroll Sheet',
                tada: 'TA/DA Adjustments',
                bonus: 'Bonus Adjustments',
                arrears: 'Arrears Adjustments',
                halfday: 'Half Day Deductions',
                ncns: 'NCNS Deductions',
                sd: 'SandWich Deductions',
                qahr: 'QA/HR Deductions',
                advance: 'Advance Salary Management',
                manual: 'Manual Overrides',
                settings: 'Global Config',
                'petty-cash': 'Petty Cash Management'
            };
            document.getElementById('mainViewTitle').textContent = viewTitles[view] || 'Finance Hub';
            
            // Panels
            document.querySelectorAll('.view-panel').forEach(p => p.classList.remove('active'));
            const activePanel = document.getElementById(`panel-${view}`);
            if (activePanel) activePanel.classList.add('active');
            
            // Auto-collapse sidebar for Attendance Grid to maximize full page view
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                if (view === 'attendance') {
                    sidebar.classList.add('collapsed');
                } else {
                    sidebar.classList.remove('collapsed');
                }
            }
            
            // Run view specific trigger
            if (view === 'users') {
                loadFinanceUsers();
            } else if (view === 'petty-cash') {
                if (typeof PettyCash !== 'undefined') PettyCash.init();
            } else if (view !== 'overview') {
                renderPayrollDashboardView(view);
            }
        }


        // ===== PAYROLL CALCULATIONS =====
        const BASE_SALARY = 0;
        const PERFECT_ATTENDANCE_BONUS = 0;
        const CHECKIN_CUTOFF_MINUTES = 18 * 60; // 6:00 PM
        const NCNS_PENALTY = 5000;
        const MISSPUNCH_DEDUCTION = 1000;
        const PROBATION_DAYS = 60;
        const TAX_RATE = 0;

        let payrollAdj = {
            tada: {}, arrears: {}, bonus: {}, halfDay: {}, ncns: {}, sd: {},
            qaHr: {}, misspunch: {}, advance: {}, manualLate: {}, manualPunctuality: {},
            manualLeaves: {}, tax: {}, appointmentDate: {}, empMeta: {},
            attendanceOverrides: {}, extraDays: {}
        };

        function convertTo12Hour(time24h) {
            if (!time24h || time24h === '--:--' || time24h === '---') return '--:--';
            if (time24h.match(/(AM|PM)/i)) return time24h;
            try {
                if (time24h.includes(':')) {
                    const parts = time24h.split(':');
                    let hour = parseInt(parts[0]);
                    const minute = parts[1];
                    const period = hour >= 12 ? 'PM' : 'AM';
                    let hour12 = hour % 12;
                    if (hour12 === 0) hour12 = 12;
                    return `${hour12}:${minute} ${period}`;
                }
                return time24h;
            } catch(e) { return time24h; }
        }

        function isWeekend(year, month, day) {
            const date = new Date(year, month - 1, day);
            return date.getDay() === 0 || date.getDay() === 6;
        }

        function getWorkingDaysCount(year, month, throughDay = null) {
            let count = 0;
            const daysInSelectedMonth = new Date(year, month, 0).getDate();
            const days = throughDay === null ? daysInSelectedMonth : Math.min(Math.max(throughDay, 0), daysInSelectedMonth);
            for (let day = 1; day <= days; day++) {
                if (!isWeekend(year, month, day)) count++;
            }
            return count;
        }

        function getCalculationEndDay(year, month) {
            const now = new Date();
            const selectedMonth = new Date(year, month - 1, 1);
            const currentMonthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            if (selectedMonth < currentMonthStart) return new Date(year, month, 0).getDate();
            if (selectedMonth > currentMonthStart) return 0;
            return now.getDate();
        }

        function isCheckinLate(checkinTime) {
            if (!checkinTime || checkinTime === '--:--') return false;
            const match = String(checkinTime).trim().match(/^(\d{1,2}):(\d{2})(?:\s*(AM|PM))?/i);
            if (!match) return false;
            let hour = Number(match[1]);
            const minute = Number(match[2]);
            const period = (match[3] || '').toUpperCase();
            if (period === 'PM' && hour !== 12) hour += 12;
            if (period === 'AM' && hour === 12) hour = 0;
            return (hour * 60 + minute) > CHECKIN_CUTOFF_MINUTES;
        }

        async function loadAllAdj() {
            try {
                const res = await fetch(`${PAYROLL_API}?action=getMonthBundle&month=${payrollMonthStr()}`, { credentials: 'include' });
                const data = await res.json();
                if (data.success && data.data) {
                    const b = data.data.bundle || {};
                    payrollAdj = {
                        tada: b.tada || {}, arrears: b.arrears || {}, bonus: b.bonus || {},
                        halfDay: b.halfDay || {}, ncns: b.ncns || {}, sd: b.sd || {},
                        qaHr: b.qaHr || {}, misspunch: b.misspunch || {}, advance: b.advance || {},
                        manualLate: b.manualLate || {}, manualPunctuality: b.manualPunctuality || {},
                        manualLeaves: b.manualLeaves || {}, tax: b.tax || {},
                        remarks: b.remarks || {}, comments: b.comments || {},
                        appointmentDate: b.appointmentDate || {}, empMeta: b.empMeta || {},
                        attendanceOverrides: b.attendanceOverrides || {}, extraDays: b.extraDays || {}
                    };
                    if (data.data.leaves) {
                        leaves = data.data.leaves;
                    }
                }
            } catch (e) {
                console.error('Failed to load adjustments', e);
            }
        }

        function payrollMonthStr() {
            return `${currentYear}-${String(currentMonth).padStart(2, '0')}`;
        }

        async function persistAllAdjNow() {
            try {
                await fetch(`${PAYROLL_API}?action=saveMonthBundle`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        month: payrollMonthStr(),
                        bundle: payrollAdj,
                        leaves: leaves
                    })
                });
            } catch (e) {
                console.error('Save failed', e);
            }
        }

        let payrollSaveTimer = null;
        function persistAllAdj() {
            clearTimeout(payrollSaveTimer);
            payrollSaveTimer = setTimeout(() => persistAllAdjNow(), 500);
        }

        function getEmpMeta(empId) {
            // First check if a saved override exists in the month bundle for this month
            if (payrollAdj.empMeta[empId]) {
                const saved = payrollAdj.empMeta[empId];
                // If it was saved with dummy salary (50000), update it from the live database
                if (parseFloat(saved.basicSalary) === 50000 || parseFloat(saved.basicSalary) === 0) {
                    const dbUser = financeUsersList.find(u => String(u.employee_code) === String(empId) || String(u.id) === String(empId));
                    if (dbUser) {
                        saved.basicSalary = parseFloat(dbUser.basic_salary) || 0;
                        saved.punctualityEnabled = !!parseInt(dbUser.punctuality_enabled);
                        saved.punctualityAmount = parseFloat(dbUser.punctuality_amount) || 0;
                    }
                }
                return saved;
            }
            
            // Otherwise check the database settings loaded from API
            const dbUser = financeUsersList.find(u => String(u.employee_code) === String(empId) || String(u.id) === String(empId));
            if (dbUser) {
                return {
                    basicSalary: parseFloat(dbUser.basic_salary) || 0,
                    punctualityEnabled: !!parseInt(dbUser.punctuality_enabled),
                    punctualityAmount: parseFloat(dbUser.punctuality_amount) || 0,
                    sudoName: dbUser.full_name || '',
                    designation: dbUser.designation || '',
                    cnic: dbUser.cnic || '',
                    bankName: dbUser.bank_name || '',
                    accountNo: dbUser.account_no || '',
                    accountTitle: dbUser.account_title || ''
                };
            }

            return {
                basicSalary: 0,
                punctualityEnabled: false,
                punctualityAmount: 0,
                sudoName: '', designation: '', cnic: '', bankName: '', accountNo: '', accountTitle: ''
            };
        }

        function sumAdjustments(empId, adjType) {
            const list = payrollAdj[adjType][empId] || [];
            return list.reduce((s, x) => s + (parseFloat(x.amount) || 0), 0);
        }

        function countAdjustments(empId, adjType) {
            return (payrollAdj[adjType][empId] || []).length;
        }

        function isProbationCompleted(emp) {
            if (!emp.appointmentDate) return false;
            try {
                const apptDate = new Date(emp.appointmentDate);
                if (Number.isNaN(apptDate.getTime())) return false;
                const calculationEndDay = getCalculationEndDay(currentYear, currentMonth);
                if (calculationEndDay === 0) return false;
                const eligibilityDate = new Date(currentYear, currentMonth - 1, calculationEndDay);
                const diffDays = Math.floor((eligibilityDate - apptDate) / (1000 * 60 * 60 * 24));
                return diffDays >= PROBATION_DAYS;
            } catch(e) { return false; }
        }

        function calculatePayrollForEmployee(emp) {
            const meta = getEmpMeta(emp.id);
            const basicSalary = parseFloat(meta.basicSalary) || BASE_SALARY;
            const configuredPunctuality = parseFloat(meta.punctualityAmount);
            const punctualityBonus = meta.punctualityEnabled
                ? (Number.isFinite(configuredPunctuality) ? configuredPunctuality : PERFECT_ATTENDANCE_BONUS)
                : 0;
            const totalSalary = basicSalary + punctualityBonus;
            const perDaySalary = workingDaysCount > 0 ? basicSalary / workingDaysCount : 0;

            const rawAbsence = Math.max(0, (emp.elapsed_working_days ?? elapsedWorkingDaysCount) - emp.present);
            const hasManualLeaveOverride = Object.prototype.hasOwnProperty.call(payrollAdj.manualLeaves, emp.id);
            const manualLeaveAllowed = parseInt(payrollAdj.manualLeaves[emp.id] || 0) > 0;
            const recordedLeaveAllowed = Number(emp.leave || 0) > 0;
            const automaticLeaveAllowed = !hasManualLeaveOverride && isProbationCompleted(emp) && rawAbsence > 0;
            const adjustedLeaveCount = rawAbsence > 0 && (manualLeaveAllowed || recordedLeaveAllowed || automaticLeaveAllowed) ? 1 : 0;
            const adjustedAbsent = Math.max(0, rawAbsence - adjustedLeaveCount);
            const totalWorkingDays = emp.present + adjustedLeaveCount;
            const payrollMonthComplete = elapsedWorkingDaysCount >= workingDaysCount;

            // Punctuality Reward Rule based on 60-Day Tenure:
            // 1. If appointment date is 60+ days old, allow up to 1 leave and still award punctuality (more than 1 leave removes it).
            // 2. If appointment date is under 60 days, even 1 leave removes punctuality (0 leaves required).
            let tenureDays = 0;
            let isTenure60Plus = false;
            if (emp.appointmentDate) {
                try {
                    const apptDate = new Date(emp.appointmentDate);
                    if (!Number.isNaN(apptDate.getTime())) {
                        const calculationEndDay = getCalculationEndDay(currentYear, currentMonth);
                        const calcDate = new Date(currentYear, currentMonth - 1, calculationEndDay || 1);
                        tenureDays = Math.floor((calcDate - apptDate) / (1000 * 60 * 60 * 24));
                        if (tenureDays >= 60) {
                            isTenure60Plus = true;
                        }
                    }
                } catch (e) {
                    isTenure60Plus = false;
                }
            }

            let punctualityQualified = false;
            let punctualityAmount = 0;
            const manualPunc = payrollAdj.manualPunctuality[emp.id];
            if (manualPunc !== undefined) {
                punctualityAmount = parseFloat(manualPunc) || 0;
                punctualityQualified = punctualityAmount > 0;
            } else if (meta.punctualityEnabled && payrollMonthComplete && emp.late === 0 && adjustedAbsent === 0) {
                const leaveAllowancePermitted = isTenure60Plus ? (adjustedLeaveCount <= 1) : (adjustedLeaveCount === 0);
                if (leaveAllowancePermitted && totalWorkingDays === workingDaysCount) {
                    punctualityQualified = true;
                    punctualityAmount = punctualityBonus;
                }
            }

            // Late Coming Logic:
            // 1. Each late coming is 300 PKR.
            // 2. Up to 3 late comings are allowed without monetary deduction (only punctuality is lost if > 0).
            // 3. If late > 3, ALL late comings are deducted (e.g. 4 late comings = 4 * 300 = 1200 PKR).
            const LATE_RATE_PER_DAY = 300;
            let lateDeduction = 0;
            if (emp.late > 3) {
                lateDeduction = emp.late * LATE_RATE_PER_DAY;
            }
            const manualLate = parseFloat(payrollAdj.manualLate[emp.id] || 0);
            if (manualLate > 0) lateDeduction = manualLate;

            const tada = sumAdjustments(emp.id, 'tada');
            const bonus = sumAdjustments(emp.id, 'bonus');
            const arrears = sumAdjustments(emp.id, 'arrears');
            let extraDays = (emp.extraDays !== undefined ? parseFloat(emp.extraDays) : Math.max(0, emp.present - workingDaysCount)) || 0;
            let extraDayPay = extraDays * perDaySalary;
            const halfDayCount = countAdjustments(emp.id, 'halfDay') + (emp.overrideHdCount || 0);
            const halfDayAmount = halfDayCount * (perDaySalary / 2);
            const unpaidCount = (emp.overrideUnpaidCount || 0);
            const unpaidDeduction = unpaidCount * perDaySalary;
            const ncnsCount = countAdjustments(emp.id, 'ncns') + (emp.overrideNcnsCount || 0);
            const ncnsAmount = ncnsCount * NCNS_PENALTY;
            const sdCount = countAdjustments(emp.id, 'sd') + (emp.overrideSdCount || 0);
            const sdAmount = sdCount * (perDaySalary * 2);
            const qaHrAmount = sumAdjustments(emp.id, 'qaHr');
            const misspunchCount = countAdjustments(emp.id, 'misspunch') + (emp.overrideMpCount || 0);
            const misspunchAmount = misspunchCount * MISSPUNCH_DEDUCTION;
            
            const advanceData = payrollAdj.advance[emp.id];
            let advanceDeduction = 0;
            let advanceRemaining = 0;
            if (advanceData) {
                const remaining = (parseFloat(advanceData.total) || 0) - (parseFloat(advanceData.paid) || 0);
                if (remaining > 0) {
                    const monthKey = `${currentYear}-${String(currentMonth).padStart(2,'0')}`;
                    const skipMonths = advanceData.skipMonths || [];
                    if (!skipMonths.includes(monthKey)) {
                        advanceDeduction = Math.min(parseFloat(advanceData.perMonth) || 0, remaining);
                    }
                }
                advanceRemaining = remaining - advanceDeduction;
            }

            const absentDeduction = adjustedAbsent * perDaySalary;

            // Calculations Order:
            // 1. Add all valid additions to totalEarnings
            // 2. Subtract every non-tax deduction once to get subNetSalary (SUB Net Salary)
            const earningsBase = totalWorkingDays * perDaySalary;
            const totalAdditions = punctualityAmount + bonus + tada + arrears + extraDayPay;
            const totalEarnings = earningsBase + totalAdditions;

            const nonTaxDeductions = absentDeduction + lateDeduction + halfDayAmount + ncnsAmount + sdAmount + qaHrAmount + misspunchAmount + advanceDeduction + unpaidDeduction;
            const subNetSalary = Math.max(0, totalEarnings - nonTaxDeductions);

            // 3. Tax Calculation: Annual Taxable Salary = SUB Net Salary x 12 using Pakistan salaried tax slabs FY 2026-2027
            let tax = parseFloat(payrollAdj.tax[emp.id] || 0);
            if (tax === 0) {
                const annualIncome = subNetSalary * 12;
                let annualTax = 0;
                if (annualIncome <= 600000) {
                    annualTax = 0;
                } else if (annualIncome <= 1200000) {
                    annualTax = (annualIncome - 600000) * 0.01;
                } else if (annualIncome <= 2200000) {
                    annualTax = 6000 + (annualIncome - 1200000) * 0.11;
                } else if (annualIncome <= 3200000) {
                    annualTax = 116000 + (annualIncome - 2200000) * 0.20;
                } else if (annualIncome <= 4100000) {
                    annualTax = 316000 + (annualIncome - 3200000) * 0.25;
                } else if (annualIncome <= 5600000) {
                    annualTax = 541000 + (annualIncome - 4100000) * 0.29;
                } else if (annualIncome <= 7000000) {
                    annualTax = 976000 + (annualIncome - 5600000) * 0.32;
                } else {
                    annualTax = 1424000 + (annualIncome - 7000000) * 0.35;
                }
                tax = Math.round(annualTax / 12);
            }

            const totalDeductions = nonTaxDeductions + tax;
            const finalNetSalary = Math.max(0, subNetSalary - tax);

            let status = 'Good', statusClass = 'badge-perfect';
            if (!payrollMonthComplete && adjustedAbsent === 0 && emp.late === 0) { status = 'Accruing'; statusClass = 'badge-perfect'; }
            else if (punctualityQualified && adjustedAbsent === 0 && emp.late === 0) { status = 'Perfect'; statusClass = 'badge-perfect'; }
            else if (adjustedAbsent > 2) { status = 'Critical'; statusClass = 'badge-danger'; }
            else if (emp.late > 0 || adjustedAbsent > 0) { status = 'Warning'; statusClass = 'badge-warning'; }

            const remarks = (payrollAdj.remarks && payrollAdj.remarks[emp.id]) || 'Ready for Payment';
            const comments = (payrollAdj.comments && payrollAdj.comments[emp.id]) || '';

            return {
                ...emp,
                meta, basicSalary, punctualityBonus, totalSalary, perDaySalary,
                approvedLeaves: adjustedLeaveCount, adjustedLeaveCount, adjustedAbsent, totalWorkingDays,
                rawAbsence, payrollMonthComplete, tenureDays, isTenure60Plus,
                punctualityQualified, punctualityAmount,
                lateDeduction, tada, bonus, arrears, extraDays, extraDayPay,
                halfDayCount, halfDayAmount, unpaidCount, unpaidDeduction, ncnsCount, ncnsAmount, sdCount, sdAmount,
                qaHrAmount, misspunchCount, misspunchAmount,
                advanceDeduction, advanceRemaining, absentDeduction, nonTaxDeductions, tax,
                totalAdditions, totalEarnings, totalDeductions,
                subNetSalary, finalNetSalary,
                grossSalary: subNetSalary,
                netSalary: finalNetSalary,
                remarks, comments,
                status, statusClass
            };
        }

        // ===== LOAD SYSTEM ATTENDANCE DATA =====
        async function loadAttendanceData() {
            const monthPicker = document.getElementById('monthPicker').value;
            const [year, month] = monthPicker.split('-');
            currentYear = parseInt(year);
            currentMonth = parseInt(month);
            daysInMonth = new Date(currentYear, currentMonth, 0).getDate();
            workingDaysCount = getWorkingDaysCount(currentYear, currentMonth);
            elapsedWorkingDaysCount = getWorkingDaysCount(currentYear, currentMonth, getCalculationEndDay(currentYear, currentMonth));

            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const periodLabel = elapsedWorkingDaysCount < workingDaysCount ? ` · ${elapsedWorkingDaysCount} elapsed` : '';
            document.getElementById('monthInfoSub').textContent = `${monthNames[currentMonth - 1]} ${currentYear} (${workingDaysCount} Working Days${periodLabel})`;

            const startDate = `${year}-${month.padStart(2,'0')}-01`;
            const endDate   = `${year}-${month.padStart(2,'0')}-${String(daysInMonth).padStart(2,'0')}`;

            document.getElementById('tableBody').innerHTML = `
                <tr><td colspan="10"><div class="loading-state"><div class="loading-spinner"></div><p>Calculating live data...</p></div></td></tr>`;

            try {
                try {
                    const uRes = await fetch('api/payroll_api.php?action=getFinanceUsers');
                    const uData = await uRes.json();
                    if (uData.success && uData.data) {
                        financeUsersList = uData.data;
                    }
                } catch(ue) {
                    console.error('Failed to pre-load finance users', ue);
                }

                const [summaryResult] = await Promise.all([
                    fetch(API_BASE + `attendance-api.php?action=getDateRange&start_date=${startDate}&end_date=${endDate}`, { credentials: 'include' }).then(r => r.json()),
                    loadAllAdj()
                ]);

                if (!summaryResult.success || !summaryResult.data || !summaryResult.data.report) {
                    document.getElementById('tableBody').innerHTML = `<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--danger);"><i class="fas fa-exclamation-circle"></i> No attendance records for ${monthNames[currentMonth-1]} ${currentYear}</td></tr>`;
                    return;
                }

                const gridResponse = await fetch(API_BASE + `attendance-api.php?action=getMonthlyGrid&month=${year}-${month.padStart(2,'0')}`, { credentials: 'include' }).then(r => r.json());
                const dailyCheckins = {};
                if (gridResponse.success && gridResponse.data && gridResponse.data.grid) {
                    gridResponse.data.grid.forEach(emp => {
                        const empId = emp.code;
                        dailyCheckins[empId] = {};
                        for (let day = 1; day <= daysInMonth; day++) {
                            const dateStr = `${year}-${month.padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                            dailyCheckins[empId][dateStr] = convertTo12Hour(emp.attendance[day] || '--:--');
                        }
                    });
                }

                rawAllData = summaryResult.data.report.map(emp => {
                    const dailyTimes = {};
                    const dailyCodes = {};
                    let presentCount = 0, lateCount = 0, leaveCount = 0;
                    let overrideMpCount = 0, overrideSdCount = 0, overrideNcnsCount = 0, overrideAbsentCount = 0;
                    const paidLeaveDates = [];
                    const empLeaves = leaves[emp.code] || [];
                    const calculationEndDay = getCalculationEndDay(currentYear, currentMonth);
                    const empOverrides = (payrollAdj.attendanceOverrides && payrollAdj.attendanceOverrides[emp.code]) || {};

                    for (let day = 1; day <= daysInMonth; day++) {
                        const dateStr = `${year}-${month.padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                        const checkin = dailyCheckins[emp.code] ? dailyCheckins[emp.code][dateStr] : null;
                        const formattedTime = checkin || '--:--';
                        dailyTimes[day] = formattedTime;

                        const overrideCode = empOverrides[dateStr];
                        if (overrideCode !== undefined && overrideCode !== null) {
                            dailyCodes[day] = overrideCode;
                            const codeUpper = String(overrideCode).toUpperCase();
                            if (codeUpper === 'P') {
                                presentCount++;
                            } else if (codeUpper === 'MP') {
                                presentCount++;
                                overrideMpCount++;
                            } else if (codeUpper === 'SD') {
                                overrideAbsentCount++;
                                overrideSdCount++;
                            } else if (codeUpper === 'NCNS') {
                                overrideAbsentCount++;
                                overrideNcnsCount++;
                            } else if (codeUpper === '') {
                                overrideAbsentCount++;
                            }
                        } else {
                            const isLeaveDay = empLeaves.some(l => l.date === dateStr);
                            const isWorkingDay = !isWeekend(currentYear, currentMonth, day) && day <= calculationEndDay;
                            if (isWorkingDay && formattedTime !== '--:--') {
                                presentCount++;
                                if (isCheckinLate(formattedTime)) lateCount++;
                                dailyCodes[day] = 'P';
                            } else if (isWorkingDay && isLeaveDay && leaveCount < 1) {
                                leaveCount = 1;
                                paidLeaveDates.push(dateStr);
                                dailyCodes[day] = 'Leave';
                            } else {
                                dailyCodes[day] = '';
                            }
                        }
                    }
                    const absent = Math.max(0, elapsedWorkingDaysCount - presentCount - leaveCount) + overrideAbsentCount;
                    const attendance_rate = elapsedWorkingDaysCount > 0 ? Math.round((presentCount / elapsedWorkingDaysCount) * 100) : 0;
                    const savedExtraDays = payrollAdj.extraDays && payrollAdj.extraDays[emp.code] !== undefined ? parseFloat(payrollAdj.extraDays[emp.code]) : 0;

                    return {
                        id: emp.code, name: emp.name, department: emp.department || 'General',
                        designation: emp.designation || 'Employee', branch: emp.branch || 'Main', team: emp.team || 'No Team',
                        cnic: emp.cnic || '', contact: emp.contact || '', accountNo: emp.account_no || '',
                        accountTitle: emp.account_title || '', bankName: emp.bank_name || '',
                        appointmentDate: payrollAdj.appointmentDate[emp.code] || emp.appointment_date || '',
                        present: presentCount, late: lateCount, absent: absent, leave: leaveCount,
                        overrideMpCount, overrideSdCount, overrideNcnsCount, extraDays: savedExtraDays,
                        working_days: workingDaysCount, elapsed_working_days: elapsedWorkingDaysCount, attendance_rate: attendance_rate,
                        attendance: dailyTimes, attendanceCodes: dailyCodes, leaves: empLeaves, paidLeaveDates
                    };
                });

                applyBranchFilter();

            } catch (error) {
                console.error(error);
                document.getElementById('tableBody').innerHTML = `<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--danger);">Failed to calculate data.</td></tr>`;
            }
        }

        function applyBranchFilter() {
            const branch = document.getElementById('headerBranchFilter').value;
            if (!branch) {
                allData = [...rawAllData];
            } else {
                allData = rawAllData.filter(emp => emp.branch && emp.branch.toLowerCase() === branch.toLowerCase());
            }

            calculateStats();
            renderTable();
            updateCharts();
            
            // If active view is payroll panel, render it
            if (activeView !== 'overview' && activeView !== 'users') {
                renderPayrollDashboardView(activeView);
            }
        }

        async function filterBranchChanged() {
            const selectEl = document.getElementById('headerBranchFilter');
            const branch = selectEl.value;
            const normBranch = branch ? branch.toLowerCase() : 'main';
            
            try {
                const res = await fetch(`api/payroll_api.php?action=switchBranch&branch=${normBranch}`);
                const data = await res.json();
                if (data.success) {
                    showToast(`Active branch switched to ${selectEl.options[selectEl.selectedIndex].text}`, 'success');
                    await initPage();
                } else {
                    showToast('Failed to switch branch: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch(e) {
                console.error(e);
                showToast('Error connecting to switch branch API', 'error');
            }
        }

        function formatCompactCurrency(amount) {
            const num = Math.abs(parseFloat(amount) || 0);
            const sign = (parseFloat(amount) || 0) < 0 ? '-' : '';
            if (num >= 1000000000) {
                return `${sign}Rs ${(num / 1000000000).toFixed(1).replace(/\.0$/, '')} Billion`;
            } else if (num >= 1000000) {
                return `${sign}Rs ${(num / 1000000).toFixed(1).replace(/\.0$/, '')} Million`;
            } else if (num >= 1000) {
                return `${sign}Rs ${(num / 1000).toFixed(1).replace(/\.0$/, '')} Thousand`;
            } else {
                return `${sign}Rs ${Math.round(num).toLocaleString('en-PK')}`;
            }
        }

        function formatExactCurrency(amount) {
            return 'Rs ' + Math.round(parseFloat(amount) || 0).toLocaleString('en-PK');
        }

        let pettyCashOverviewSummary = { approvedAmount: 0, pendingAmount: 0 };

        async function fetchOverviewPettyCash() {
            try {
                const month = `${currentYear}-${String(currentMonth).padStart(2, '0')}`;
                const res = await fetch(`api/petty_cash_api.php?action=getDashboard&month=${encodeURIComponent(month)}`);
                const data = await res.json();
                if (data.success && data.data && data.data.stats) {
                    pettyCashOverviewSummary.approvedAmount = parseFloat(data.data.stats.approved_amount) || 0;
                    pettyCashOverviewSummary.pendingAmount = parseFloat(data.data.stats.pending_amount) || 0;
                    updateOverviewPettyCards();
                }
            } catch(e) {}
        }

        function updateOverviewPettyCards() {
            const setAmountCard = (valueId, cardId, subId, amount) => {
                const valEl = document.getElementById(valueId);
                const cardEl = document.getElementById(cardId);
                const subEl = document.getElementById(subId);
                if (valEl) valEl.textContent = formatCompactCurrency(amount);
                if (cardEl) cardEl.title = formatExactCurrency(amount);
                if (subEl) subEl.innerHTML = `<i class="fas fa-info-circle"></i> Exact: ${formatExactCurrency(amount)}`;
            };

            setAmountCard('statApprovedPettyCash', 'cardApprovedPettyCash', 'subtextApprovedPettyCash', pettyCashOverviewSummary.approvedAmount);
            setAmountCard('statPendingPettyCash', 'cardPendingPettyCash', 'subtextPendingPettyCash', pettyCashOverviewSummary.pendingAmount);
        }

        function calculateStats() {
            const payrollData = allData.map(emp => calculatePayrollForEmployee(emp));
            
            const totalFinalNet = payrollData.reduce((s, e) => s + (e.finalNetSalary || 0), 0);
            const totalGross = payrollData.reduce((s, e) => s + (e.totalEarnings || 0), 0);
            const totalDeductions = payrollData.reduce((s, e) => s + (e.totalDeductions || 0), 0);
            const totalTax = payrollData.reduce((s, e) => s + (e.tax || 0), 0);
            const totalAdditions = payrollData.reduce((s, e) => s + (e.totalAdditions || 0), 0);
            const totalBonus = payrollData.reduce((s, e) => s + (e.bonus || 0), 0);

            const paidEmployees = payrollData.filter(e => (e.finalNetSalary || 0) > 0).length;
            const unpaidEmployees = payrollData.length - paidEmployees;

            const setAmountCard = (valueId, cardId, subId, amount) => {
                const valEl = document.getElementById(valueId);
                const cardEl = document.getElementById(cardId);
                const subEl = document.getElementById(subId);
                if (valEl) valEl.textContent = formatCompactCurrency(amount);
                if (cardEl) cardEl.title = formatExactCurrency(amount);
                if (subEl) subEl.innerHTML = `<i class="fas fa-info-circle"></i> Exact: ${formatExactCurrency(amount)}`;
            };

            setAmountCard('statFinalNetSalary', 'cardFinalNetSalary', 'subtextFinalNetSalary', totalFinalNet);
            setAmountCard('statGrossPayroll', 'cardGrossPayroll', 'subtextGrossPayroll', totalGross);
            setAmountCard('statTotalDeductions', 'cardTotalDeductions', 'subtextTotalDeductions', totalDeductions);
            setAmountCard('statTotalTax', 'cardTotalTax', 'subtextTotalTax', totalTax);
            setAmountCard('statTotalAdditions', 'cardTotalAdditions', 'subtextTotalAdditions', totalAdditions);
            setAmountCard('statTotalBonus', 'cardTotalBonus', 'subtextTotalBonus', totalBonus);

            updateOverviewPettyCards();

            const totEmpEl = document.getElementById('statTotalEmployees');
            if (totEmpEl) totEmpEl.textContent = allData.length.toLocaleString();

            const paidEmpEl = document.getElementById('statPaidEmployees');
            if (paidEmpEl) paidEmpEl.textContent = paidEmployees.toLocaleString();

            const unpaidEmpEl = document.getElementById('statUnpaidEmployees');
            if (unpaidEmpEl) unpaidEmpEl.textContent = unpaidEmployees.toLocaleString();

            fetchOverviewPettyCash();
        }

        function renderTable() {
            let filtered = allData;
            const deptFilter = document.getElementById('departmentFilter').value;
            if (deptFilter) {
                filtered = filtered.filter(e => e.department && e.department.toLowerCase() === deptFilter.toLowerCase());
            }
            const tlFilter = document.getElementById('teamLeadFilter').value;
            if (tlFilter === 'Team Lead') {
                filtered = filtered.filter(e => {
                    const desig = (e.designation || '').toLowerCase();
                    const team = (e.team || '').toLowerCase();
                    return desig.includes('team lead') || desig.includes('lead') || team.includes('team lead');
                });
            }
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            if (search) {
                filtered = filtered.filter(e => e.name.toLowerCase().includes(search) || e.id.includes(search));
            }

            if (filtered.length === 0) {
                document.getElementById('tableBody').innerHTML = '<tr><td colspan="11"><div class="loading-state"><p>No personnel found</p></div></td></tr>';
                return;
            }

            let headerHtml = `<tr><th>ID</th><th>PERSONNEL</th><th>DEPARTMENT</th><th>DESIGNATION</th><th>BRANCH</th><th>TEAM</th>`;
            for (let day = 1; day <= daysInMonth; day++) {
                const monthAbbr = getMonthAbbr(currentMonth).toUpperCase();
                const isWk = isWeekend(currentYear, currentMonth, day);
                headerHtml += `<th style="text-align:center; padding:8px 6px; ${isWk ? 'color: #c084fc;' : ''}"><div style="font-size:13px; font-weight:700;">${day}</div><div style="font-size:9px; font-weight:800; letter-spacing:1px; margin-top:2px; ${isWk ? 'color:#a78bfa;' : 'color:var(--text-muted);'}">${monthAbbr}</div></th>`;
            }
            headerHtml += `<th>Present</th><th>Absent</th><th>Late</th><th>Leave</th><th>Extra Days</th><th>Actions</th></tr>`;
            document.getElementById('tableHeader').innerHTML = headerHtml;

            document.getElementById('tableBody').innerHTML = filtered.map(emp => {
                const initials = emp.name ? emp.name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2) : '??';
                let rowHtml = `<tr>` +
                    `<td><span style="font-weight:700; color:white;">${emp.id}</span></td>` +
                    `<td><div style="display:flex;align-items:center;gap:10px;"><div style="width:34px;height:34px;background:#ea580c;border-radius:10px;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:12px;box-shadow: 0 2px 8px rgba(234,88,12,0.4);">${initials}</div><div><div style="font-weight:700; color:white; font-size:13px;">${emp.name}</div><div style="font-size:11px;color:var(--text-muted);">${emp.id}</div></div></div></td>` +
                    `<td><span class="badge-dept">${emp.department || 'General'}</span></td>` +
                    `<td><span class="badge-desig">${emp.designation || 'Employee'}</span></td>` +
                    `<td><span class="badge-branch">${emp.branch || 'Main'}</span></td>` +
                    `<td><span class="badge-team">${emp.team || 'No Team'}</span></td>`;
                
                for (let day = 1; day <= daysInMonth; day++) {
                    const dateStr = `${currentYear}-${String(currentMonth).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                    const currentCode = (emp.attendanceCodes && emp.attendanceCodes[day] !== undefined) ? emp.attendanceCodes[day] : '';
                    const clsKey = currentCode ? currentCode.toUpperCase() : 'BLANK';
                    const checkinTime = (emp.attendance && emp.attendance[day]) ? emp.attendance[day] : '--:--';
                    const hasCheckin = checkinTime !== '--:--';
                    
                    const timeBadge = `<div style="font-size:10px; font-weight:700; color:${hasCheckin ? '#38bdf8' : 'rgba(255,255,255,0.25)'}; margin-bottom:3px; letter-spacing:0.3px;">${checkinTime}</div>`;

                    const selectHtml = `<select class="att-select code-${clsKey}" onchange="updateAttendanceCell('${emp.id}', '${dateStr}', this.value)" onclick="event.stopPropagation();">
                        <option value="P" ${currentCode === 'P' ? 'selected' : ''}>P</option>
                        <option value="MP" ${currentCode === 'MP' ? 'selected' : ''}>MP</option>
                        <option value="HD" ${currentCode === 'HD' ? 'selected' : ''}>HD</option>
                        <option value="UNPAID" ${currentCode === 'UNPAID' ? 'selected' : ''}>Unpaid</option>
                        <option value="LEAVE" ${currentCode === 'LEAVE' ? 'selected' : ''}>Leave</option>
                        <option value="SD" ${currentCode === 'SD' ? 'selected' : ''}>SD</option>
                        <option value="NCNS" ${currentCode === 'NCNS' ? 'selected' : ''}>NCNS</option>
                        <option value="" ${currentCode === '' ? 'selected' : ''}>Absent (Blank)</option>
                    </select>`;

                    rowHtml += `<td style="text-align:center; padding:6px 4px; vertical-align:middle;"><div style="display:flex; flex-direction:column; align-items:center; justify-content:center;">${timeBadge}${selectHtml}</div></td>`;
                }

                const extraDaysVal = emp.extraDays || 0;
                rowHtml += `<td><span class="summary-badge summary-present">${emp.present}</span></td>` +
                    `<td><span class="summary-badge summary-absent">${emp.absent}</span></td>` +
                    `<td><span class="summary-badge summary-late">${emp.late}</span></td>` +
                    `<td><span class="summary-badge summary-leave">${emp.leave}</span></td>` +
                    `<td><input type="number" min="0" step="0.5" class="extra-days-input" value="${extraDaysVal}" onchange="updateExtraDays('${emp.id}', this.value)" onclick="event.stopPropagation();"></td>` +
                    `<td><button class="view-btn" onclick="event.stopPropagation();viewEmployeeDetails('${emp.id}', '${emp.name.replace(/'/g, "\\'")}')"><i class="fas fa-eye"></i></button></td>` +
                    `</tr>`;
                return rowHtml;
            }).join('');
        }

        function updateAttendanceCell(empId, dateStr, codeVal) {
            if (!payrollAdj.attendanceOverrides) payrollAdj.attendanceOverrides = {};
            if (!payrollAdj.attendanceOverrides[empId]) payrollAdj.attendanceOverrides[empId] = {};
            payrollAdj.attendanceOverrides[empId][dateStr] = codeVal;
            persistAllAdj();

            // Recalculate and re-render
            const emp = rawAllData.find(e => e.id === empId);
            if (emp) {
                const daysInM = new Date(currentYear, currentMonth, 0).getDate();
                let presentCount = 0, overrideMpCount = 0, overrideSdCount = 0, overrideNcnsCount = 0, overrideAbsentCount = 0, leaveCount = 0;
                let overrideUnpaidCount = 0, overrideHdCount = 0, overrideLeaveCount = 0;
                const empLeaves = leaves[empId] || [];
                const calculationEndDay = getCalculationEndDay(currentYear, currentMonth);
                const empOverrides = payrollAdj.attendanceOverrides[empId] || {};

                for (let day = 1; day <= daysInM; day++) {
                    const dStr = `${currentYear}-${String(currentMonth).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                    const code = empOverrides[dStr];
                    if (code !== undefined && code !== null) {
                        emp.attendanceCodes[day] = code;
                        const codeUpper = String(code).toUpperCase();
                        if (codeUpper === 'P') presentCount++;
                        else if (codeUpper === 'MP') { presentCount++; overrideMpCount++; }
                        else if (codeUpper === 'UNPAID') { presentCount++; overrideUnpaidCount++; }
                        else if (codeUpper === 'HD') { presentCount += 0.5; overrideHdCount++; }
                        else if (codeUpper === 'LEAVE') { leaveCount++; overrideLeaveCount++; }
                        else if (codeUpper === 'SD') { overrideAbsentCount++; overrideSdCount++; }
                        else if (codeUpper === 'NCNS') { overrideAbsentCount++; overrideNcnsCount++; }
                        else if (codeUpper === '') overrideAbsentCount++;
                    } else {
                        const formattedTime = emp.attendance[day] || '--:--';
                        const isLeaveDay = empLeaves.some(l => l.date === dStr);
                        const isWorkingDay = !isWeekend(currentYear, currentMonth, day) && day <= calculationEndDay;
                        if (isWorkingDay && formattedTime !== '--:--') {
                            presentCount++;
                            emp.attendanceCodes[day] = 'P';
                        } else if (isWorkingDay && isLeaveDay && leaveCount < 1) {
                            leaveCount = 1;
                            emp.attendanceCodes[day] = 'Leave';
                        } else {
                            emp.attendanceCodes[day] = '';
                        }
                    }
                }
                emp.present = presentCount;
                emp.overrideMpCount = overrideMpCount;
                emp.overrideSdCount = overrideSdCount;
                emp.overrideNcnsCount = overrideNcnsCount;
                emp.overrideUnpaidCount = overrideUnpaidCount;
                emp.overrideHdCount = overrideHdCount;
                emp.overrideLeaveCount = overrideLeaveCount;
                emp.leave = leaveCount;
                emp.absent = Math.max(0, elapsedWorkingDaysCount - Math.floor(presentCount) - leaveCount) + overrideAbsentCount;
            }

            calculateStats();
            renderTable();
            showToast('✅ Attendance code updated', 'success');
        }

        function updateExtraDays(empId, val) {
            if (!payrollAdj.extraDays) payrollAdj.extraDays = {};
            const num = Math.max(0, parseFloat(val) || 0);
            payrollAdj.extraDays[empId] = num;
            persistAllAdj();

            const emp = rawAllData.find(e => e.id === empId);
            if (emp) emp.extraDays = num;

            calculateStats();
            renderTable();
            showToast('✅ Extra Days updated', 'success');
        }

        window.updateAttendanceCell = updateAttendanceCell;
        window.updateExtraDays = updateExtraDays;

        function getMonthAbbr(month) { return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][month - 1]; }

        function updateCharts() {
            const last7Days = []; const attendanceData = [];
            for (let i = 6; i >= 0; i--) {
                const date = new Date(); date.setDate(date.getDate() - i);
                last7Days.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                attendanceData.push(Math.floor(Math.random() * 50) + 1200);
            }
            if (attendanceTrendChart) attendanceTrendChart.destroy();
            const ctx1 = document.getElementById('attendanceTrendChart').getContext('2d');
            attendanceTrendChart = new Chart(ctx1, { type: 'line', data: { labels: last7Days, datasets: [{ label: 'Present Employees', data: attendanceData, borderColor: '#f97316', backgroundColor: 'rgba(249, 115, 22, 0.05)', fill: true, tension: 0.4, pointBackgroundColor: '#f97316', pointBorderColor: 'white', pointRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#9ca3af' } } }, scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9ca3af' } }, x: { ticks: { color: '#9ca3af' } } } } });
            
            const deptMap = new Map();
            allData.forEach(emp => { deptMap.set(emp.department, (deptMap.get(emp.department) || 0) + 1); });
            const sortedDepts = Array.from(deptMap.entries()).sort((a, b) => b[1] - a[1]).slice(0, 6);
            if (departmentChart) departmentChart.destroy();
            const ctx2 = document.getElementById('departmentChart').getContext('2d');
            departmentChart = new Chart(ctx2, { type: 'doughnut', data: { labels: sortedDepts.map(d => d[0]), datasets: [{ data: sortedDepts.map(d => d[1]), backgroundColor: ['#f97316', '#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#9ca3af', boxWidth: 10, font: { size: 10 } } } }, cutout: '70%' } });

            // 1. Calculate Department payroll costs
            const payrollData = allData.map(emp => calculatePayrollForEmployee(emp));
            const deptCostMap = new Map();
            payrollData.forEach(p => {
                const dept = p.department || 'General';
                deptCostMap.set(dept, (deptCostMap.get(dept) || 0) + p.grossSalary);
            });
            const sortedDeptCosts = Array.from(deptCostMap.entries()).sort((a,b) => b[1] - a[1]).slice(0, 6);
            
            if (deptPayrollChart) deptPayrollChart.destroy();
            const ctx3 = document.getElementById('deptPayrollChart').getContext('2d');
            deptPayrollChart = new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: sortedDeptCosts.map(d => d[0]),
                    datasets: [{
                        label: 'Gross Payroll Costs (₨)',
                        data: sortedDeptCosts.map(d => Math.round(d[1])),
                        backgroundColor: '#f97316',
                        borderColor: '#ea580c',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9ca3af' } },
                        x: { ticks: { color: '#9ca3af' } }
                    }
                }
            });

            // 2. Calculate Salary Breakdown (Basic vs Allowances vs Deductions)
            const sumBasic = payrollData.reduce((s, e) => s + e.basicSalary, 0);
            const sumAllowances = payrollData.reduce((s, e) => s + (e.bonus + e.tada + e.arrears + e.extraDayPay + e.punctualityAmount), 0);
            const sumDeductions = payrollData.reduce((s, e) => s + e.totalDeductions, 0);

            if (salaryBreakdownChart) salaryBreakdownChart.destroy();
            const ctx4 = document.getElementById('salaryBreakdownChart').getContext('2d');
            salaryBreakdownChart = new Chart(ctx4, {
                type: 'pie',
                data: {
                    labels: ['Basic Salaries', 'Allowances & Bonuses', 'Active Deductions'],
                    datasets: [{
                        data: [Math.round(sumBasic), Math.round(sumAllowances), Math.round(sumDeductions)],
                        backgroundColor: ['#10b981', '#3b82f6', '#ef4444'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#9ca3af', boxWidth: 10, font: { size: 10 } }
                        }
                    }
                }
            });
        }

        // ===== RENDER INLINE PAYROLL MODULE VIEWS =====
        function renderPayrollDashboardView(view) {
            const panel = document.getElementById(`panel-${view}`);
            if (!panel) return;

            const payrollData = allData.map(emp => calculatePayrollForEmployee(emp));
            const totalGross = payrollData.reduce((s, e) => s + e.totalSalary, 0);
            const totalEarnings = payrollData.reduce((s, e) => s + e.totalEarnings, 0);
            const totalDeductionsSum = payrollData.reduce((s, e) => s + e.totalDeductions, 0);
            const totalNet = payrollData.reduce((s, e) => s + e.netSalary, 0);
            const totalTada = payrollData.reduce((s, e) => s + e.tada, 0);
            const totalBonusAmt = payrollData.reduce((s, e) => s + e.bonus, 0);
            const totalArrears = payrollData.reduce((s, e) => s + e.arrears, 0);
            const totalAdvance = payrollData.reduce((s, e) => s + e.advanceDeduction, 0);

            if (view === 'payroll-sheet') {
                panel.innerHTML = renderFullPayrollTable(payrollData);
            } else if (view === 'bank-format') {
                panel.innerHTML = renderBankFormatView(payrollData);
            } else if (view === 'tada') {
                panel.innerHTML = renderAdjustmentTab('tada', 'TA/DA (Travel Allowance)', 'fa-plane', 'positive');
            } else if (view === 'bonus') {
                panel.innerHTML = renderAdjustmentTab('bonus', 'Bonus', 'fa-gift', 'positive');
            } else if (view === 'arrears') {
                panel.innerHTML = renderAdjustmentTab('arrears', 'Arrears', 'fa-money-bill-wave', 'positive');
            } else if (view === 'halfday') {
                panel.innerHTML = renderAdjustmentTab('halfDay', 'Half Day', 'fa-hourglass-half', 'negative', true);
            } else if (view === 'ncns') {
                panel.innerHTML = renderAdjustmentTab('ncns', 'NCNS (No Call No Show)', 'fa-user-times', 'negative', true);
            } else if (view === 'sd') {
                panel.innerHTML = renderAdjustmentTab('sd', 'SandWich (SD)', 'fa-bread-slice', 'negative', true);
            } else if (view === 'qahr') {
                panel.innerHTML = renderAdjustmentTab('qaHr', 'QA/HR Docs', 'fa-clipboard-check', 'negative');
            } else if (view === 'advance') {
                panel.innerHTML = renderAdvanceTab();
            } else if (view === 'manual') {
                panel.innerHTML = renderManualTab();
            } else if (view === 'settings') {
                panel.innerHTML = renderSettingsTab();
            }
        }

        // ===== BANK FORMAT SALARY FILE GENERATOR MODULE =====
        let bankFormatState = {
            sourceBank: '',
            transferType: '',
            txDate: new Date().toISOString().split('T')[0],
            validRecords: [],
            invalidRecords: [],
            bankDb: [],
            bankMappings: {},
            companyAccounts: {},
            isLoaded: false
        };

        async function loadBankFormatMetadata() {
            if (bankFormatState.isLoaded) return;
            try {
                const res = await fetch('api/payroll_api.php?action=getBankCodeMappings');
                const data = await res.json();
                if (data.success && data.data) {
                    bankFormatState.bankDb = data.data.banks || [];
                    bankFormatState.bankMappings = data.data.mappings || {};
                    bankFormatState.companyAccounts = data.data.companyAccounts || {};
                    bankFormatState.isLoaded = true;
                }
            } catch (e) {
                console.error('Failed to load bank metadata', e);
            }
        }

        function resolveCanonicalBank(bankNameText) {
            if (!bankNameText || !bankFormatState.bankDb.length) return null;
            const str = String(bankNameText).toLowerCase().trim();
            if (!str) return null;

            // Direct normalization aliases
            if (str.includes('askari')) return bankFormatState.bankDb.find(b => b.norm.includes('askari'));
            if (str.includes('alfalah')) return bankFormatState.bankDb.find(b => b.norm.includes('alfalah'));
            if (str.includes('meezan')) return bankFormatState.bankDb.find(b => b.norm.includes('meezan'));
            if (str.includes('hbl') || str.includes('habib bank limited')) return bankFormatState.bankDb.find(b => b.norm === 'hbl');
            if (str.includes('ubl') || str.includes('united bank')) return bankFormatState.bankDb.find(b => b.norm === 'ubl');
            if (str.includes('abl') || str.includes('allied bank')) return bankFormatState.bankDb.find(b => b.norm === 'abl');
            if (str.includes('mcb')) return bankFormatState.bankDb.find(b => b.norm === 'mcb');
            if (str.includes('faysal')) return bankFormatState.bankDb.find(b => b.norm.includes('faysal'));
            if (str.includes('al habib')) return bankFormatState.bankDb.find(b => b.norm === 'bank al habib');
            if (str.includes('standard chartered') || str.includes('scb')) return bankFormatState.bankDb.find(b => b.norm.includes('standard chartered'));
            if (str.includes('js bank')) return bankFormatState.bankDb.find(b => b.norm.includes('js bank'));
            if (str.includes('dubai islamic') || str.includes('dib')) return bankFormatState.bankDb.find(b => b.norm.includes('dubai islamic'));
            if (str.includes('bankislami')) return bankFormatState.bankDb.find(b => b.norm.includes('bankislami'));
            if (str.includes('soneri')) return bankFormatState.bankDb.find(b => b.norm.includes('soneri'));
            if (str.includes('habib metro')) return bankFormatState.bankDb.find(b => b.norm.includes('habib metro'));
            if (str.includes('nbp') || str.includes('national bank')) return bankFormatState.bankDb.find(b => b.norm === 'nbp');
            if (str.includes('bop') || str.includes('punjab')) return bankFormatState.bankDb.find(b => b.norm === 'bop');
            if (str.includes('summit')) return bankFormatState.bankDb.find(b => b.norm.includes('summit'));
            if (str.includes('al baraka')) return bankFormatState.bankDb.find(b => b.norm.includes('al baraka'));
            if (str.includes('samba')) return bankFormatState.bankDb.find(b => b.norm.includes('samba'));
            if (str.includes('silkbank')) return bankFormatState.bankDb.find(b => b.norm.includes('silkbank'));
            if (str.includes('fwbl') || str.includes('women bank')) return bankFormatState.bankDb.find(b => b.norm === 'fwbl');
            if (str.includes('jazzcash') || str.includes('mobilink')) return bankFormatState.bankDb.find(b => b.norm.includes('jazzcash'));
            if (str.includes('easypaisa') || str.includes('telenor')) return bankFormatState.bankDb.find(b => b.norm.includes('easypaisa'));
            if (str.includes('nayapay')) return bankFormatState.bankDb.find(b => b.norm.includes('nayapay'));
            if (str.includes('sadapay')) return bankFormatState.bankDb.find(b => b.norm.includes('sadapay'));

            // Exact or substring match fallback
            return bankFormatState.bankDb.find(b => str.includes(b.norm) || b.norm.includes(str)) || null;
        }

        function generateUniqueTxRef(empId, sourceBank, month, year) {
            const mAbbr = getMonthAbbr(month).toUpperCase();
            const yShort = String(year).slice(-2);
            const prefix = sourceBank === 'ASKARI' ? 'ASK' : 'ALF';
            return `${mAbbr}${yShort}${prefix}${empId}`;
        }

        function onBankFormatSourceChanged(val) {
            bankFormatState.sourceBank = val;
            bankFormatState.transferType = '';
            const typeSelect = document.getElementById('bfTransferType');
            if (typeSelect) {
                if (!val) {
                    typeSelect.disabled = true;
                    typeSelect.innerHTML = '<option value="">Select Source Bank First</option>';
                } else {
                    typeSelect.disabled = false;
                    if (val === 'ASKARI') {
                        typeSelect.innerHTML = `
                            <option value="">Select Transfer Type</option>
                            <option value="FT">FT (Askari to Askari Bank)</option>
                            <option value="IBFT">IBFT (Askari to Other Banks)</option>
                        `;
                    } else if (val === 'ALFALAH') {
                        typeSelect.innerHTML = `
                            <option value="">Select Transfer Type</option>
                            <option value="FT">FT (Bank Alfalah IFT)</option>
                            <option value="IBFT">IBFT (Bank Alfalah IBFT)</option>
                        `;
                    }
                }
            }
        }

        function renderBankFormatView(payrollData) {
            loadBankFormatMetadata();
            const monthName = getMonthAbbr(currentMonth).toUpperCase() + ' ' + currentYear;
            const headerBranchEl = document.getElementById('headerBranchFilter');
            const branchLabel = headerBranchEl ? headerBranchEl.options[headerBranchEl.selectedIndex].text : 'All Branches';

            const exportBtnText = bankFormatState.sourceBank && bankFormatState.transferType
                ? `Export ${bankFormatState.sourceBank === 'ASKARI' ? 'Askari' : 'Bank Alfalah'} ${bankFormatState.transferType} File`
                : 'Export Bank File';

            return `
                <div class="adj-section" style="margin-bottom:20px; background:linear-gradient(145deg, rgba(15,23,42,0.7), rgba(30,41,59,0.5)); backdrop-filter:blur(16px); border:1px solid rgba(255,255,255,0.08); border-radius:20px; padding:28px; box-shadow:0 20px 50px rgba(0,0,0,0.4);">
                    <!-- Header Bar -->
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px; padding-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.08);">
                        <div style="display:flex; align-items:center; gap:16px;">
                            <div style="width:48px; height:48px; border-radius:14px; background:linear-gradient(135deg, rgba(249,115,22,0.25), rgba(59,130,246,0.25)); border:1px solid rgba(249,115,22,0.4); display:flex; align-items:center; justify-content:center; box-shadow:0 8px 20px rgba(249,115,22,0.2);">
                                <i class="fas fa-university" style="font-size:22px; color:#f97316;"></i>
                            </div>
                            <div>
                                <h2 style="font-size:20px; font-weight:800; color:white; margin:0; letter-spacing:-0.3px;">
                                    FT & IBFT Salary Bank File Generator
                                </h2>
                                <span style="font-size:12px; color:var(--text-muted); font-weight:500;">Generate official Askari & Bank Alfalah salary transfer files</span>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <span style="font-size:11px; font-weight:700; background:rgba(249,115,22,0.15); color:var(--primary); border:1px solid rgba(249,115,22,0.3); padding:6px 14px; border-radius:20px; box-shadow:0 4px 12px rgba(249,115,22,0.15);"><i class="far fa-calendar-alt"></i> ${monthName}</span>
                            <span style="font-size:11px; font-weight:700; background:rgba(59,130,246,0.15); color:#60a5fa; border:1px solid rgba(59,130,246,0.3); padding:6px 14px; border-radius:20px; box-shadow:0 4px 12px rgba(59,130,246,0.15);"><i class="fas fa-building"></i> ${escapeHtml(branchLabel)}</span>
                        </div>
                    </div>

                    <!-- Step Cards Selection Grid -->
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:18px; margin-bottom:24px;">
                        <div style="background:rgba(15,23,42,0.6); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:18px; transition:all 0.3s ease;">
                            <label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:8px;">
                                <i class="fas fa-landmark" style="color:#f97316; margin-right:6px;"></i> STEP 1: SOURCE BANK <span style="color:var(--danger)">*</span>
                            </label>
                            <select id="bfSourceBank" onchange="onBankFormatSourceChanged(this.value)" style="width:100%; background:rgba(15,23,42,0.8); border:1px solid rgba(255,255,255,0.15); color:white; font-weight:600; font-size:13px; padding:12px 14px; border-radius:12px; outline:none; transition:all 0.3s ease;">
                                <option value="">Select Source Bank</option>
                                <option value="ASKARI" ${bankFormatState.sourceBank === 'ASKARI' ? 'selected' : ''}>Askari Bank</option>
                                <option value="ALFALAH" ${bankFormatState.sourceBank === 'ALFALAH' ? 'selected' : ''}>Bank Alfalah</option>
                            </select>
                        </div>

                        <div style="background:rgba(15,23,42,0.6); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:18px; transition:all 0.3s ease;">
                            <label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:8px;">
                                <i class="fas fa-exchange-alt" style="color:#38bdf8; margin-right:6px;"></i> STEP 2: TRANSFER TYPE <span style="color:var(--danger)">*</span>
                            </label>
                            <select id="bfTransferType" ${!bankFormatState.sourceBank ? 'disabled' : ''} style="width:100%; background:rgba(15,23,42,0.8); border:1px solid rgba(255,255,255,0.15); color:white; font-weight:600; font-size:13px; padding:12px 14px; border-radius:12px; outline:none; transition:all 0.3s ease;">
                                ${!bankFormatState.sourceBank ? '<option value="">Select Source Bank First</option>' : ''}
                                ${bankFormatState.sourceBank === 'ASKARI' ? `
                                    <option value="">Select Transfer Type</option>
                                    <option value="FT" ${bankFormatState.transferType === 'FT' ? 'selected' : ''}>FT (Askari to Askari Bank)</option>
                                    <option value="IBFT" ${bankFormatState.transferType === 'IBFT' ? 'selected' : ''}>IBFT (Askari to Other Banks)</option>
                                ` : ''}
                                ${bankFormatState.sourceBank === 'ALFALAH' ? `
                                    <option value="">Select Transfer Type</option>
                                    <option value="FT" ${bankFormatState.transferType === 'FT' ? 'selected' : ''}>FT (Bank Alfalah IFT)</option>
                                    <option value="IBFT" ${bankFormatState.transferType === 'IBFT' ? 'selected' : ''}>IBFT (Bank Alfalah IBFT)</option>
                                ` : ''}
                            </select>
                        </div>

                        <div style="background:rgba(15,23,42,0.6); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:18px; transition:all 0.3s ease;">
                            <label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:8px;">
                                <i class="far fa-calendar-check" style="color:#34d399; margin-right:6px;"></i> STEP 3: TRANSACTION DATE <span style="color:var(--danger)">*</span>
                            </label>
                            <input type="date" id="bfTxDate" value="${bankFormatState.txDate}" style="width:100%; background:rgba(15,23,42,0.8); border:1px solid rgba(255,255,255,0.15); color:white; font-weight:600; font-size:13px; padding:12px 14px; border-radius:12px; outline:none; transition:all 0.3s ease;">
                        </div>
                    </div>

                    <!-- Modern Action Buttons -->
                    <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:28px;">
                        <button onclick="generateBankFormatPreview()" style="background:linear-gradient(135deg, #f97316 0%, #ea580c 100%); color:white; font-weight:700; font-size:13px; border:none; border-radius:12px; padding:12px 24px; cursor:pointer; box-shadow:0 6px 20px rgba(249,115,22,0.35); transition:all 0.25s ease; display:inline-flex; align-items:center; gap:8px;">
                            <i class="fas fa-bolt"></i> Generate Preview
                        </button>
                        <button id="btnExportBankFile" ${!bankFormatState.validRecords.length ? 'disabled style="opacity:0.4; cursor:not-allowed; background:rgba(255,255,255,0.1); color:#94a3b8; border:none; border-radius:12px; padding:12px 24px; font-weight:700; font-size:13px;"' : 'style="background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:white; font-weight:700; font-size:13px; border:none; border-radius:12px; padding:12px 24px; cursor:pointer; box-shadow:0 6px 20px rgba(16,185,129,0.35); transition:all 0.25s ease; display:inline-flex; align-items:center; gap:8px;"'} onclick="exportBankFormatFile()">
                            <i class="fas fa-file-excel"></i> ${exportBtnText}
                        </button>
                        <button onclick="resetBankFormatModule()" style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#cbd5e1; font-weight:600; font-size:13px; border-radius:12px; padding:12px 20px; cursor:pointer; transition:all 0.25s ease; display:inline-flex; align-items:center; gap:8px;">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                    </div>

                    <!-- Advanced Summary Metric Cards -->
                    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:16px; margin-bottom:28px;">
                        <div style="background:linear-gradient(145deg, rgba(30,41,59,0.7), rgba(15,23,42,0.7)); border:1px solid rgba(255,255,255,0.08); border-top:3px solid #3b82f6; border-radius:16px; padding:20px; position:relative; overflow:hidden;">
                            <i class="fas fa-users" style="position:absolute; right:-10px; bottom:-10px; font-size:64px; color:rgba(59,130,246,0.08);"></i>
                            <div style="font-size:11px; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Eligible Employees</div>
                            <div style="font-size:26px; font-weight:800; color:white; margin-top:6px; font-family:monospace;">${payrollData.length}</div>
                        </div>

                        <div style="background:linear-gradient(145deg, rgba(16,185,129,0.1), rgba(15,23,42,0.7)); border:1px solid rgba(16,185,129,0.3); border-top:3px solid #10b981; border-radius:16px; padding:20px; position:relative; overflow:hidden;">
                            <i class="fas fa-check-circle" style="position:absolute; right:-10px; bottom:-10px; font-size:64px; color:rgba(16,185,129,0.08);"></i>
                            <div style="font-size:11px; color:#34d399; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Valid Records</div>
                            <div style="font-size:26px; font-weight:800; color:#34d399; margin-top:6px; font-family:monospace;">${bankFormatState.validRecords.length}</div>
                        </div>

                        <div style="background:linear-gradient(145deg, rgba(239,68,68,0.1), rgba(15,23,42,0.7)); border:1px solid rgba(239,68,68,0.3); border-top:3px solid #ef4444; border-radius:16px; padding:20px; position:relative; overflow:hidden;">
                            <i class="fas fa-exclamation-triangle" style="position:absolute; right:-10px; bottom:-10px; font-size:64px; color:rgba(239,68,68,0.08);"></i>
                            <div style="font-size:11px; color:#f87171; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Excluded / Invalid</div>
                            <div style="font-size:26px; font-weight:800; color:#f87171; margin-top:6px; font-family:monospace;">${bankFormatState.invalidRecords.length}</div>
                        </div>

                        <div style="background:linear-gradient(145deg, rgba(249,115,22,0.1), rgba(15,23,42,0.7)); border:1px solid rgba(249,115,22,0.3); border-top:3px solid #f97316; border-radius:16px; padding:20px; position:relative; overflow:hidden;">
                            <i class="fas fa-vault" style="position:absolute; right:-10px; bottom:-10px; font-size:64px; color:rgba(249,115,22,0.08);"></i>
                            <div style="font-size:11px; color:var(--primary); font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Total Transfer Amount</div>
                            <div style="font-size:24px; font-weight:800; color:var(--primary); margin-top:6px; font-family:monospace;">₨ ${bankFormatState.validRecords.reduce((sum, r) => sum + r.finalNetSalary, 0).toLocaleString()}</div>
                        </div>
                    </div>

                    <!-- Preview Tables Section -->
                    ${renderBankFormatTables()}
                </div>
            `;
        }

        function renderBankFormatTables() {
            if (!bankFormatState.validRecords.length && !bankFormatState.invalidRecords.length) {
                return '<div style="text-align:center; padding:50px 20px; color:#94a3b8; background:rgba(15,23,42,0.4); border-radius:16px; border:1px dashed rgba(255,255,255,0.1);"><i class="fas fa-sparkles" style="font-size:28px; color:#f97316; margin-bottom:12px; display:block;"></i> Select Source Bank & Transfer Type above, then click <strong style="color:white;">Generate Preview</strong> to process records.</div>';
            }

            let validTableHtml = '';
            if (bankFormatState.validRecords.length > 0) {
                validTableHtml = `
                    <div class="table-container" style="margin-bottom:28px; border-radius:16px; overflow:hidden; border:1px solid rgba(16,185,129,0.3); background:rgba(15,23,42,0.8);">
                        <div class="table-header" style="background:rgba(16,185,129,0.08); padding:16px 20px; border-bottom:1px solid rgba(16,185,129,0.2);">
                            <h2 style="font-size:15px; font-weight:700; color:#34d399; margin:0; display:flex; align-items:center; gap:8px;">
                                <i class="fas fa-check-circle"></i> Valid Bank Transfer Records (${bankFormatState.validRecords.length})
                            </h2>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Biometric ID</th>
                                        <th>Employee Name</th>
                                        <th>Source Bank</th>
                                        <th>Destination Bank</th>
                                        <th>Bank Code</th>
                                        <th>Type</th>
                                        <th>Account Number / IBAN</th>
                                        <th>Final Net Salary</th>
                                        <th>Unique Ref</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${bankFormatState.validRecords.map(r => `
                                        <tr>
                                            <td><strong style="font-family:monospace; color:white;">${escapeHtml(r.employeeId)}</strong></td>
                                            <td><strong style="color:#f8fafc;">${escapeHtml(r.employeeName)}</strong></td>
                                            <td><span class="badge-branch">${escapeHtml(r.sourceBank)}</span></td>
                                            <td><span class="badge-dept">${escapeHtml(r.destBankName)}</span></td>
                                            <td><span style="font-family:monospace; font-weight:700; color:#38bdf8; background:rgba(56,189,248,0.15); border:1px solid rgba(56,189,248,0.3); padding:4px 10px; border-radius:8px; box-shadow:0 2px 8px rgba(56,189,248,0.15);">${escapeHtml(r.destBankCode || '—')}</span></td>
                                            <td><span class="badge-desig">${escapeHtml(r.transferType)}</span></td>
                                            <td><span style="font-family:monospace; color:#cbd5e1;">${escapeHtml(r.accountNo)}</span></td>
                                            <td><strong style="color:#34d399; font-family:monospace; font-size:14px;">₨ ${r.finalNetSalary.toLocaleString()}</strong></td>
                                            <td><span style="font-family:monospace; font-size:11px; font-weight:700; color:#fbbf24; background:rgba(251,191,36,0.12); border:1px solid rgba(251,191,36,0.3); padding:4px 10px; border-radius:8px;">${escapeHtml(r.uniqueRef)}</span></td>
                                            <td><span style="font-size:11px; font-weight:700; background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(16,185,129,0.3); padding:4px 12px; border-radius:20px; display:inline-flex; align-items:center; gap:6px;"><span style="width:6px; height:6px; border-radius:50%; background:#34d399; box-shadow:0 0 8px #34d399;"></span> Valid</span></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            let invalidTableHtml = '';
            if (bankFormatState.invalidRecords.length > 0) {
                invalidTableHtml = `
                    <div class="table-container" style="border-radius:16px; overflow:hidden; border:1px solid rgba(239,68,68,0.3); background:rgba(15,23,42,0.8);">
                        <div class="table-header" style="background:rgba(239,68,68,0.08); padding:16px 20px; border-bottom:1px solid rgba(239,68,68,0.2);">
                            <h2 style="font-size:15px; font-weight:700; color:#f87171; margin:0; display:flex; align-items:center; gap:8px;">
                                <i class="fas fa-exclamation-triangle"></i> Excluded / Invalid Records (${bankFormatState.invalidRecords.length})
                            </h2>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Employee Name</th>
                                        <th>Destination Bank</th>
                                        <th>Account Number</th>
                                        <th>Net Salary</th>
                                        <th>Error Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${bankFormatState.invalidRecords.map(r => `
                                        <tr>
                                            <td><strong style="font-family:monospace; color:white;">${escapeHtml(r.employeeId)}</strong></td>
                                            <td>${escapeHtml(r.employeeName)}</td>
                                            <td>${escapeHtml(r.destBankName || 'Not Set')}</td>
                                            <td><span style="font-family:monospace;">${escapeHtml(r.accountNo || 'Not Set')}</span></td>
                                            <td>₨ ${(r.finalNetSalary || 0).toLocaleString()}</td>
                                            <td><span style="color:#f87171; font-weight:700; font-size:12px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); padding:4px 10px; border-radius:8px; display:inline-block;">⚠️ ${escapeHtml(r.errorReason)}</span></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            return validTableHtml + invalidTableHtml;
        }

        async function generateBankFormatPreview() {
            await loadBankFormatMetadata();
            const sourceSelect = document.getElementById('bfSourceBank');
            const typeSelect = document.getElementById('bfTransferType');
            const txDateInput = document.getElementById('bfTxDate');

            const sourceBank = sourceSelect ? sourceSelect.value : '';
            const transferType = typeSelect ? typeSelect.value : '';
            const txDate = txDateInput ? txDateInput.value : '';

            if (!sourceBank) { showToast('Select Source Bank first', 'warning'); return; }
            if (!transferType) { showToast('Select Transfer Type first', 'warning'); return; }
            if (!txDate) { showToast('Select Transaction Date', 'warning'); return; }

            bankFormatState.sourceBank = sourceBank;
            bankFormatState.transferType = transferType;
            bankFormatState.txDate = txDate;
            bankFormatState.validRecords = [];
            bankFormatState.invalidRecords = [];

            const payrollData = allData.map(emp => calculatePayrollForEmployee(emp));
            const seenTxRefs = new Set();

            payrollData.forEach(emp => {
                const empRemarks = emp.remarks || (payrollAdj.remarks && payrollAdj.remarks[emp.id]) || 'Ready for Payment';
                if (empRemarks === 'Hold') {
                    return;
                }

                const meta = getEmpMeta(emp.id);
                const dbUser = financeUsersList.find(u => String(u.employee_code) === String(emp.id) || String(u.id) === String(emp.id));

                const employeeId = String(emp.id || '').trim();
                const employeeName = String(emp.name || '').trim();
                const accountTitle = String(meta.accountTitle || emp.account_title || emp.accountTitle || dbUser?.account_title || '').trim();
                const accountNo = String(meta.accountNo || emp.account_no || emp.accountNo || dbUser?.account_no || '').trim();
                const cnic = String(meta.cnic || emp.cnic || dbUser?.cnic || '').trim();
                const rawBankName = String(meta.bankName || emp.bank_name || emp.bankName || dbUser?.bank_name || '').trim();
                const contactNo = String(emp.phone || emp.contact_no || '03000000000').trim();
                const finalNetSalary = Math.round(emp.finalNetSalary);

                let errorReason = '';

                // Mandatory Validation Rules
                if (!employeeId) {
                    errorReason = 'Missing Biometric ID';
                } else if (!rawBankName) {
                    errorReason = 'Missing Destination Bank Name in User Settings';
                } else if (!accountTitle) {
                    errorReason = 'Missing Account Title in User Settings';
                } else if (!accountNo) {
                    errorReason = 'Missing Account Number / IBAN in User Settings';
                } else if (!finalNetSalary || finalNetSalary <= 0) {
                    errorReason = 'Final Net Salary is zero or negative';
                }

                if (errorReason) {
                    bankFormatState.invalidRecords.push({
                        employeeId, employeeName, destBankName: rawBankName, accountNo, finalNetSalary, errorReason
                    });
                    return;
                }

                // Resolve Canonical Bank
                const canonicalBank = resolveCanonicalBank(rawBankName);
                if (!canonicalBank) {
                    bankFormatState.invalidRecords.push({
                        employeeId, employeeName, destBankName: rawBankName, accountNo, finalNetSalary,
                        errorReason: `Unrecognized destination bank "${rawBankName}"`
                    });
                    return;
                }

                const bankId = canonicalBank.id;
                const destBankName = canonicalBank.name;
                const isAskariBank = canonicalBank.norm.includes('askari');
                const isAlfalahBank = canonicalBank.norm.includes('alfalah');

                // FT vs IBFT Classification Check
                if (sourceBank === 'ASKARI') {
                    if (transferType === 'FT' && !isAskariBank) {
                        bankFormatState.invalidRecords.push({
                            employeeId, employeeName, destBankName, accountNo, finalNetSalary,
                            errorReason: 'Askari FT requires Askari Bank destination account'
                        });
                        return;
                    }
                    if (transferType === 'IBFT' && isAskariBank) {
                        bankFormatState.invalidRecords.push({
                            employeeId, employeeName, destBankName, accountNo, finalNetSalary,
                            errorReason: 'Askari destination account belongs in Askari FT file, not IBFT'
                        });
                        return;
                    }
                } else if (sourceBank === 'ALFALAH') {
                    if (transferType === 'FT' && !isAlfalahBank) {
                        bankFormatState.invalidRecords.push({
                            employeeId, employeeName, destBankName, accountNo, finalNetSalary,
                            errorReason: 'Bank Alfalah FT (IFT) requires Bank Alfalah destination account'
                        });
                        return;
                    }
                    if (transferType === 'IBFT' && isAlfalahBank) {
                        bankFormatState.invalidRecords.push({
                            employeeId, employeeName, destBankName, accountNo, finalNetSalary,
                            errorReason: 'Bank Alfalah destination account belongs in Alfalah FT (IFT) file, not IBFT'
                        });
                        return;
                    }
                }

                // Destination Bank Code Lookup
                const bankCodeMap = (bankFormatState.bankMappings[sourceBank] || {});
                const destBankCode = bankCodeMap[bankId] || '';

                if (!destBankCode && transferType === 'IBFT') {
                    bankFormatState.invalidRecords.push({
                        employeeId, employeeName, destBankName, accountNo, finalNetSalary,
                        errorReason: sourceBank === 'ASKARI' ? 'Askari/1Link bank code not found' : `Bank code mapping not found for ${destBankName} under ${sourceBank}`
                    });
                    return;
                }

                // Additional Format Field Rules
                if (sourceBank === 'ALFALAH' && transferType === 'IBFT' && !cnic) {
                    bankFormatState.invalidRecords.push({
                        employeeId, employeeName, destBankName, accountNo, finalNetSalary,
                        errorReason: 'CNIC required for Bank Alfalah IBFT export'
                    });
                    return;
                }

                const uniqueRef = generateUniqueTxRef(employeeId, sourceBank, currentMonth, currentYear);
                if (seenTxRefs.has(uniqueRef)) {
                    bankFormatState.invalidRecords.push({
                        employeeId, employeeName, destBankName, accountNo, finalNetSalary,
                        errorReason: `Duplicate transaction reference "${uniqueRef}"`
                    });
                    return;
                }
                seenTxRefs.add(uniqueRef);

                bankFormatState.validRecords.push({
                    employeeId,
                    employeeName,
                    accountTitle,
                    accountNo,
                    cnic,
                    contactNo,
                    finalNetSalary,
                    sourceBank,
                    transferType,
                    bankId,
                    destBankName,
                    destBankCode,
                    uniqueRef
                });
            });

            // Re-render view with updated preview tables and metrics
            if (activeView === 'bank-format') {
                renderPayrollDashboardView('bank-format');
            }
            showToast(`✅ Generated preview: ${bankFormatState.validRecords.length} valid records`, 'success');
        }

        function resetBankFormatModule() {
            bankFormatState.sourceBank = '';
            bankFormatState.transferType = '';
            bankFormatState.txDate = new Date().toISOString().split('T')[0];
            bankFormatState.validRecords = [];
            bankFormatState.invalidRecords = [];
            if (activeView === 'bank-format') {
                renderPayrollDashboardView('bank-format');
            }
        }

        async function exportBankFormatFile() {
            if (!bankFormatState.validRecords.length) {
                showToast('No valid records to export', 'warning');
                return;
            }

            const confirmMsg = `Confirm Export:\n\n` +
                `• Source Bank: ${bankFormatState.sourceBank}\n` +
                `• Transfer Type: ${bankFormatState.transferType}\n` +
                `• Valid Records: ${bankFormatState.validRecords.length}\n` +
                `• Excluded Invalid Records: ${bankFormatState.invalidRecords.length}\n` +
                `• Total Transfer Amount: ₨ ${bankFormatState.validRecords.reduce((s, r) => s + r.finalNetSalary, 0).toLocaleString()}\n\n` +
                `Proceed with downloading official XLSX file?`;

            if (!confirm(confirmMsg)) return;

            const monthAbbr = getMonthAbbr(currentMonth).toUpperCase();
            const yearStr = currentYear;
            let filename = '';
            let headers = [];
            let rows = [];

            const companyDebitAcc = (bankFormatState.companyAccounts[bankFormatState.sourceBank] || '01801006543210');

            if (bankFormatState.sourceBank === 'ASKARI') {
                filename = `Askari_${bankFormatState.transferType}_${monthAbbr}_${yearStr}.xlsx`;
                headers = [
                    'S.No',
                    'Transaction Date',
                    'Beneficiary Bank Code',
                    'Beneficiary Bank Name',
                    'Beneficiary Name',
                    'Beneficiary Account No',
                    'Amount',
                    'Product Type Code',
                    'ReferenceField1',
                    'ReferenceField2',
                    'ReferenceField3',
                    'BeneficiaryMobile',
                    'BeneSMS'
                ];

                const fullMonthName = new Date(currentYear, currentMonth - 1, 1).toLocaleString('en-US', { month: 'long' });
                rows = bankFormatState.validRecords.map((r, idx) => [
                    idx + 1,
                    bankFormatState.txDate,
                    bankFormatState.transferType === 'FT' ? '104' : (r.destBankCode || '104'),
                    bankFormatState.transferType === 'FT' ? 'Askari Bank Limited' : r.destBankName,
                    r.accountTitle,
                    r.accountNo,
                    r.finalNetSalary,
                    r.transferType,
                    r.uniqueRef,
                    'Salary',
                    `FMO ${fullMonthName} ${yearStr}`,
                    r.contactNo || '03000000000',
                    'Your Salary has been credited in your salary account'
                ]);

            } else if (bankFormatState.sourceBank === 'ALFALAH') {
                if (bankFormatState.transferType === 'FT') {
                    filename = `Alfalah_FT_${monthAbbr}_${yearStr}.xlsx`;
                    headers = [
                        'Employee/Beneficiary Name',
                        'Company Debit Account',
                        'Final Net Salary',
                        'Employee Bank Alfalah Account/IBAN',
                        'Transaction Reference',
                        'Salary Narration'
                    ];

                    rows = bankFormatState.validRecords.map(r => [
                        r.accountTitle,
                        companyDebitAcc,
                        r.finalNetSalary,
                        r.accountNo,
                        r.uniqueRef,
                        `Salary ${monthAbbr} ${yearStr}`
                    ]);

                } else if (bankFormatState.transferType === 'IBFT') {
                    filename = `Alfalah_IBFT_${monthAbbr}_${yearStr}.xlsx`;
                    headers = [
                        'Destination Bank Code',
                        'Employee Account Number/IBAN',
                        'Employee/Beneficiary Name',
                        'Transaction Reference',
                        'Final Net Salary',
                        'Identification Type',
                        'Employee CNIC',
                        'Purpose/Transaction Code',
                        'Company Debit Account'
                    ];

                    rows = bankFormatState.validRecords.map(r => [
                        r.destBankCode,
                        r.accountNo,
                        r.accountTitle,
                        r.uniqueRef,
                        r.finalNetSalary,
                        'CNIC',
                        r.cnic,
                        '030',
                        companyDebitAcc
                    ]);
                }
            }

            try {
                showToast('⏳ Generating official bank XLSX file...', 'info');
                const res = await fetch('api/payroll_api.php?action=exportBankXlsx', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename, headers, rows })
                });

                if (!res.ok) {
                    showToast('Failed to generate export file', 'danger');
                    return;
                }

                const blob = await res.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(url);

                showToast(`✅ Downloaded ${filename}`, 'success');
            } catch (e) {
                console.error(e);
                showToast('Error exporting bank file', 'danger');
            }
        }

        function filterPayrollByTeam(team) {
            selectedPayrollTeam = team;
            if (activeView === 'payroll-sheet') {
                renderPayrollDashboardView('payroll-sheet');
            }
        }
        window.filterPayrollByTeam = filterPayrollByTeam;

        function filterPayrollSearch(query) {
            selectedPayrollQuery = (query || '').trim().toLowerCase();
            if (activeView === 'payroll-sheet') {
                renderPayrollDashboardView('payroll-sheet');
                const searchInput = document.getElementById('payrollSearchInput');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
                }
            }
        }
        window.filterPayrollSearch = filterPayrollSearch;

        function getRemarksSelectClass(status) {
            switch (status) {
                case 'Ready for Payment': return 'status-ready';
                case 'Hold': return 'status-hold';
                case 'Paid': return 'status-paid';
                case 'Unpaid': return 'status-unpaid';
                case 'Failed': return 'status-failed';
                default: return 'status-ready';
            }
        }

        function updatePayrollRemarks(empId, newStatus) {
            if (!payrollAdj.remarks) payrollAdj.remarks = {};
            payrollAdj.remarks[empId] = newStatus;

            const rowEl = document.getElementById(`payroll-row-${empId}`);
            const commentInput = document.getElementById(`comment-input-${empId}`);
            const selectEl = document.getElementById(`remarks-select-${empId}`);

            if (newStatus === 'Hold') {
                if (rowEl) rowEl.classList.add('row-on-hold');
                const currentComment = (payrollAdj.comments && payrollAdj.comments[empId]) || '';
                if (!currentComment.trim()) {
                    if (commentInput) {
                        commentInput.classList.add('comment-required-error');
                        commentInput.placeholder = 'Comment required for Hold!';
                        commentInput.focus();
                    }
                    showToast('⚠️ Comment is required when setting an employee on Hold', 'warning');
                }
            } else {
                if (rowEl) rowEl.classList.remove('row-on-hold');
                if (commentInput) {
                    commentInput.classList.remove('comment-required-error');
                    commentInput.placeholder = 'Add comment...';
                }
            }

            if (selectEl) {
                selectEl.className = 'payroll-remarks-select ' + getRemarksSelectClass(newStatus);
            }

            persistAllAdj();
            showToast(`Remarks updated to "${newStatus}"`, 'info');
        }
        window.updatePayrollRemarks = updatePayrollRemarks;

        function onPayrollCommentInput(empId, val) {
            if (!payrollAdj.comments) payrollAdj.comments = {};
            payrollAdj.comments[empId] = val;
            const currentRemarks = (payrollAdj.remarks && payrollAdj.remarks[empId]) || 'Ready for Payment';
            const commentInput = document.getElementById(`comment-input-${empId}`);
            if (currentRemarks === 'Hold' && val.trim()) {
                if (commentInput) commentInput.classList.remove('comment-required-error');
            }
        }
        window.onPayrollCommentInput = onPayrollCommentInput;

        function updatePayrollComment(empId, val) {
            if (!payrollAdj.comments) payrollAdj.comments = {};
            payrollAdj.comments[empId] = val;
            const currentRemarks = (payrollAdj.remarks && payrollAdj.remarks[empId]) || 'Ready for Payment';
            const commentInput = document.getElementById(`comment-input-${empId}`);

            if (currentRemarks === 'Hold' && !val.trim()) {
                if (commentInput) {
                    commentInput.classList.add('comment-required-error');
                    commentInput.focus();
                }
                showToast('⚠️ Comment is required for employees on Hold status', 'warning');
            } else if (commentInput) {
                commentInput.classList.remove('comment-required-error');
            }

            persistAllAdj();
        }
        window.updatePayrollComment = updatePayrollComment;

        function renderFullPayrollTable(payrollData) {
            const fmtTxt = val => escapeHtml(val !== undefined && val !== null && String(val).trim() !== '' ? String(val) : '—');
            const fmtAmt = val => '₨ ' + (parseFloat(val) || 0).toLocaleString();
            const fmtCnt = val => parseInt(val) || 0;

            // Extract unique teams from current branch employee dataset
            const branchTeams = Array.from(new Set(allData.map(e => (e.team || '').trim()).filter(t => t !== '' && t.toLowerCase() !== 'no team'))).sort();
            if (allData.some(e => !e.team || (e.team || '').trim().toLowerCase() === 'no team')) {
                branchTeams.push('No Team');
            }

            // Filter payroll data by selected team and search query (Name or Biometric ID)
            let displayPayrollData = payrollData;
            if (selectedPayrollTeam) {
                displayPayrollData = displayPayrollData.filter(e => {
                    const empTeam = (e.team || 'No Team').trim().toLowerCase();
                    return empTeam === selectedPayrollTeam.trim().toLowerCase();
                });
            }
            if (selectedPayrollQuery) {
                displayPayrollData = displayPayrollData.filter(e => {
                    const name = (e.name || '').toLowerCase();
                    const id = (e.id || '').toLowerCase();
                    const sudo = (e.meta ? (e.meta.sudoName || e.meta.sudo_name || '') : '').toLowerCase();
                    return name.includes(selectedPayrollQuery) || id.includes(selectedPayrollQuery) || sudo.includes(selectedPayrollQuery);
                });
            }

            const headerBranchEl = document.getElementById('headerBranchFilter');
            const activeBranchLabel = headerBranchEl ? headerBranchEl.options[headerBranchEl.selectedIndex].text : 'All Branches';

            return `
                <div class="adj-section" style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <h3><i class="fas fa-table"></i> Complete Payroll Sheet</h3>
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div style="display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.04); border:1px solid var(--border-color); padding:6px 14px; border-radius:10px;">
                            <i class="fas fa-search" style="color:var(--primary); font-size:13px;"></i>
                            <input type="text" id="payrollSearchInput" value="${escapeHtml(selectedPayrollQuery)}" oninput="filterPayrollSearch(this.value)" placeholder="Search Name or B-ID..." style="background:transparent; border:none; color:white; font-size:13px; outline:none; width:180px;">
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.04); border:1px solid var(--border-color); padding:6px 14px; border-radius:10px;">
                            <i class="fas fa-users-cog" style="color:var(--primary); font-size:13px;"></i>
                            <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Team Filter (${escapeHtml(activeBranchLabel)}):</label>
                            <select id="payrollTeamFilter" class="filter-select" onchange="filterPayrollByTeam(this.value)" style="background:transparent; border:none; color:white; font-weight:600; font-size:13px; outline:none; cursor:pointer;">
                                <option value="" style="background:#0f1524;">All Teams (${displayPayrollData.length} Personnel)</option>
                                ${branchTeams.map(t => `<option value="${escapeHtml(t)}" ${selectedPayrollTeam === t ? 'selected' : ''} style="background:#0f1524;">${escapeHtml(t)}</option>`).join('')}
                            </select>
                        </div>
                        <button class="btn btn-secondary" onclick="exportBankTransferCSV()" style="padding: 8px 16px; font-size:12px; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); color:white;"><i class="fas fa-university"></i> Export Bank Transfer CSV</button>
                    </div>
                </div>
                <div class="table-container">
                <div class="table-wrapper">
                <table id="payrollTable">
                    <thead><tr>
                        <th>B-ID</th>
                        <th>Employees Name</th>
                        <th>Sudo Names</th>
                        <th>Designation</th>
                        <th>Campaign</th>
                        <th>CNIC#</th>
                        <th>Contact No.</th>
                        <th>Account Nos</th>
                        <th>Account Title</th>
                        <th>Bank Name</th>
                        <th>Appointment Date</th>
                        <th>Basic Salary</th>
                        <th>Punctuality</th>
                        <th>Total Salary</th>
                        <th>Salary Per Day</th>
                        <th>Num of Days</th>
                        <th>Present</th>
                        <th>Leave</th>
                        <th>Absent</th>
                        <th>Total No of W.Days</th>
                        <th>Punch Reward</th>
                        <th>Bonus</th>
                        <th>TA/DA</th>
                        <th>Arrears</th>
                        <th>Extra Day</th>
                        <th>Extra Day Pay</th>
                        <th>Late Coming</th>
                        <th>Late Coming Deduction</th>
                        <th>HD</th>
                        <th>HD Deduction</th>
                        <th>SD</th>
                        <th>SD Deduction</th>
                        <th>NCNS</th>
                        <th>NCNS Deduction</th>
                        <th>Unpaid Days</th>
                        <th>Unpaid Deduction</th>
                        <th>Docs</th>
                        <th>Missed Punchin</th>
                        <th>Missed Punchin Deduction</th>
                        <th>Transport Deduction</th>
                        <th>Advance Salary</th>
                        <th>Absent Deduction</th>
                        <th>Total Addition</th>
                        <th>Gross Salary</th>
                        <th>Total Deduction Ept Tax</th>
                        <th>SUB - Net Salary</th>
                        <th>Tax</th>
                        <th style="color:#34d399; font-weight:800;">Final Net Salary</th>
                        <th>Remarks</th>
                        <th>Comments</th>
                        <th>Action</th>
                     </tr></thead>
                     <tbody>
                        ${displayPayrollData.map(e => {
                            const sudoName = e.meta ? (e.meta.sudoName || e.meta.sudo_name || '') : '';
                            const desig = e.meta ? (e.meta.designation || e.designation || '') : (e.designation || '');
                            const cnic = e.meta ? (e.meta.cnic || '') : '';
                            const campaign = e.department || e.campaign || '';
                            const finalNet = Math.round(e.finalNetSalary);
                            const totalDedExceptTax = Math.round(e.nonTaxDeductions);
                            const empRemarks = e.remarks || (payrollAdj.remarks && payrollAdj.remarks[e.id]) || 'Ready for Payment';
                            const empComments = e.comments || (payrollAdj.comments && payrollAdj.comments[e.id]) || '';
                            const isHold = empRemarks === 'Hold';
                            const selectClass = getRemarksSelectClass(empRemarks);

                            return `
                            <tr class="${isHold ? 'row-on-hold' : ''}" id="payroll-row-${e.id}">
                                <td>${fmtTxt(e.id)}</td>
                                <td><strong>${fmtTxt(e.name)}</strong></td>
                                <td>${fmtTxt(sudoName)}</td>
                                <td>${fmtTxt(desig)}</td>
                                <td>${fmtTxt(campaign)}</td>
                                <td>${fmtTxt(cnic)}</td>
                                <td>${fmtTxt(e.phone || e.contact_no)}</td>
                                <td>${fmtTxt(e.account_no)}</td>
                                <td>${fmtTxt(e.account_title)}</td>
                                <td>${fmtTxt(e.bank_name)}</td>
                                <td>${fmtTxt(e.appointmentDate)}</td>
                                <td>${fmtAmt(e.basicSalary)}</td>
                                <td>${fmtAmt(e.punctualityBonus)}</td>
                                <td>${fmtAmt(e.totalSalary)}</td>
                                <td>${fmtAmt(Math.round(e.perDaySalary))}</td>
                                <td>${fmtCnt(workingDaysCount)}</td>
                                <td>${fmtCnt(e.present)}</td>
                                <td>${fmtCnt(e.adjustedLeaveCount)}</td>
                                <td>${fmtCnt(e.adjustedAbsent)}</td>
                                <td>${fmtCnt(e.totalWorkingDays)}</td>
                                <td>${fmtAmt(e.punctualityAmount)}</td>
                                <td>${fmtAmt(e.bonus)}</td>
                                <td>${fmtAmt(e.tada)}</td>
                                <td>${fmtAmt(e.arrears)}</td>
                                <td>${fmtCnt(e.extraDays)}</td>
                                <td>${fmtAmt(Math.round(e.extraDayPay))}</td>
                                <td>${fmtCnt(e.late)}</td>
                                <td>${fmtAmt(Math.round(e.lateDeduction))}</td>
                                <td>${fmtCnt(e.halfDayCount)}</td>
                                <td>${fmtAmt(Math.round(e.halfDayAmount))}</td>
                                <td>${fmtCnt(e.sdCount)}</td>
                                <td>${fmtAmt(Math.round(e.sdAmount))}</td>
                                <td>${fmtCnt(e.ncnsCount)}</td>
                                <td>${fmtAmt(e.ncnsAmount)}</td>
                                <td>${fmtCnt(e.unpaidCount || 0)}</td>
                                <td>${fmtAmt(Math.round(e.unpaidDeduction || 0))}</td>
                                <td>${fmtAmt(e.qaHrAmount)}</td>
                                <td>${fmtCnt(e.misspunchCount)}</td>
                                <td>${fmtAmt(e.misspunchAmount)}</td>
                                <td>${fmtAmt(0)}</td>
                                <td>${fmtAmt(e.advanceDeduction)}</td>
                                <td>${fmtAmt(Math.round(e.absentDeduction))}</td>
                                <td>${fmtAmt(Math.round(e.totalAdditions))}</td>
                                <td>${fmtAmt(Math.round(e.totalEarnings))}</td>
                                <td>${fmtAmt(totalDedExceptTax)}</td>
                                <td>${fmtAmt(Math.round(e.subNetSalary))}</td>
                                <td>${fmtAmt(e.tax)}</td>
                                <td class="highlight-net-salary">${fmtAmt(finalNet)}</td>
                                <td>
                                    <select class="payroll-remarks-select ${selectClass}" id="remarks-select-${e.id}" onchange="updatePayrollRemarks('${e.id}', this.value)">
                                        <option value="Ready for Payment" ${empRemarks === 'Ready for Payment' ? 'selected' : ''}>Ready for Payment</option>
                                        <option value="Hold" ${empRemarks === 'Hold' ? 'selected' : ''}>Hold</option>
                                        <option value="Paid" ${empRemarks === 'Paid' ? 'selected' : ''}>Paid</option>
                                        <option value="Unpaid" ${empRemarks === 'Unpaid' ? 'selected' : ''}>Unpaid</option>
                                        <option value="Failed" ${empRemarks === 'Failed' ? 'selected' : ''}>Failed</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" 
                                           class="payroll-comment-input ${isHold && !empComments.trim() ? 'comment-required-error' : ''}" 
                                           id="comment-input-${e.id}"
                                           value="${escapeHtml(empComments)}" 
                                           placeholder="${isHold ? 'Comment required...' : 'Add comment...'}" 
                                           onchange="updatePayrollComment('${e.id}', this.value)" 
                                           oninput="onPayrollCommentInput('${e.id}', this.value)">
                                </td>
                                <td><button class="fum-edit-btn" onclick="viewPayrollSlip('${e.id}', event)"><i class="fas fa-receipt"></i> Slip</button></td>
                            </tr>
                            `;
                        }).join('')}
                     </tbody>
                </table>
                </div>
                </div>
            `;
        }

        function renderAdjustmentTab(type, label, icon, sign, isPerDay) {
            const items = payrollAdj[type];
            let listHtml = '';
            let total = 0; let totalCount = 0;
            allData.forEach(emp => {
                const arr = items[emp.id] || [];
                arr.forEach((it, idx) => {
                    totalCount++;
                    const amtVal = parseFloat(it.amount) || 0;
                    total += amtVal;
                    const isNeg = amtVal < 0 || sign === 'negative';
                    const displayAmt = Math.abs(amtVal).toLocaleString();
                    const signSymbol = amtVal < 0 ? '-' : (sign === 'negative' ? '-' : '+');
                    const badgeText = amtVal < 0 ? ' <span style="font-size:10px; background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.3); padding:1px 6px; border-radius:8px; margin-left:6px;">Deduction</span>' : '';

                    listHtml += `<div class="adj-item">
                        <div class="name">${emp.name} <span style="color:var(--text-muted);font-size:10px;">(${emp.id})</span>${badgeText}</div>
                        <div class="amt ${isNeg ? 'neg' : ''}">${signSymbol}₨ ${displayAmt}</div>
                        <div class="reason">${it.reason || '—'} ${it.date ? `<span style="color:#3b82f6;margin-left:12px;">📅 ${it.date}</span>` : ''}</div>
                        <div style="color:var(--text-muted);font-size:10px;">${it.addedAt ? new Date(it.addedAt).toLocaleDateString() : ''}</div>
                        <button class="adj-delete" onclick="deleteAdjItem('${type}','${emp.id}',${idx})"><i class="fas fa-trash"></i></button>
                    </div>`;
                });
            });
            if (!listHtml) listHtml = '<div style="text-align:center;padding:30px;color:var(--text-muted);">No records found.</div>';

            return `
                <div class="adj-section">
                    <h3><i class="fas ${icon}"></i> ${label}</h3>
                    <div class="adj-form-grid">
                        <div class="search-input-wrapper">
                            <label class="adj-label">Search Employee</label>
                            <input type="text" class="adj-input" id="${type}-emp-search" placeholder="Type name or ID..." 
                                onkeyup="renderEmployeeSearchResults('${type}-emp-search', this.value)">
                        </div>
                        ${['tada', 'bonus', 'arrears'].includes(type) ? `
                        <div>
                            <label class="adj-label">Entry Mode</label>
                            <select class="adj-input" id="adj-mode-${type}" style="background:rgba(255,255,255,0.06); color:white; font-weight:600; outline:none;">
                                <option value="addition" style="background:#0f1524; color:#34d399;">➕ Addition (+ Allowance)</option>
                                <option value="deduction" style="background:#0f1524; color:#f87171;">➖ Deduction (- Salary Penalty)</option>
                            </select>
                        </div>` : ''}
                        ${isPerDay ? `<div><label class="adj-label">Date</label><input type="date" class="adj-input" id="adj-date-${type}" value="${currentYear}-${String(currentMonth).padStart(2,'0')}-01"></div>` : ''}
                        ${!isPerDay || ['halfDay','sd'].includes(type) ? `<div><label class="adj-label">Amount</label>
                            <input type="number" class="adj-input" id="adj-amt-${type}" placeholder="Enter amount"></div>` : ''}
                        <div><label class="adj-label">Reason / Comments</label><input type="text" class="adj-input" id="adj-reason-${type}" placeholder="Comments..."></div>
                    </div>
                    <button class="btn btn-primary" onclick="addAdjItemFromSearch('${type}',${isPerDay})"><i class="fas fa-plus"></i> Add Entry</button>
                    <button class="btn btn-secondary" onclick="triggerCSVUpload('${type}')" style="margin-left:8px; background: rgba(255,255,255,0.05); color: white; border: 1px solid var(--border-color);"><i class="fas fa-file-upload"></i> Bulk Upload CSV</button>
                    <a href="#" onclick="downloadCSVTemplate('${type}', ${isPerDay}); return false;" style="margin-left:12px; font-size:12px; color: var(--primary); text-decoration: none; display: inline-block; vertical-align: middle;"><i class="fas fa-download"></i> Template</a>
                    <input type="file" id="csv-file-input-${type}" style="display:none;" accept=".csv" onchange="handleCSVFileSelected(event, '${type}', ${isPerDay})">
                    
                    <div style="margin-top:24px;padding:16px;background:rgba(255,255,255,0.02);border:1px solid var(--border-color);border-radius:12px;display:flex;justify-content:space-between;">
                        <div style="font-size:12px;color:var(--text-muted)">Records: <strong style="color:white;">${totalCount}</strong></div>
                        <div style="font-size:12px;color:var(--text-muted)">Net Total: <strong style="color:${total < 0 || sign==='negative'?'var(--danger)':'var(--secondary)'};">${total < 0 ? '-' : (sign==='negative'?'-':'+')}₨ ${Math.abs(total).toLocaleString()}</strong></div>
                    </div>
                    <div class="adj-list">${listHtml}</div>
                </div>
            `;
        }

        function renderEmployeeSearchResults(inputId, searchValue) {
            const inputWrapper = document.getElementById(inputId)?.closest('.search-input-wrapper');
            if (!inputWrapper) return;
            const existingResults = inputWrapper.querySelector('.employee-search-results');
            if (existingResults) existingResults.remove();
            if (!searchValue || searchValue.length < 1) return;
            const filtered = allData.filter(emp => 
                emp.name.toLowerCase().includes(searchValue.toLowerCase()) || emp.id.includes(searchValue)
            ).slice(0, 5);
            if (filtered.length === 0) return;
            const resultsDiv = document.createElement('div');
            resultsDiv.className = 'employee-search-results';
            filtered.forEach(emp => {
                const item = document.createElement('div');
                item.className = 'employee-search-item';
                item.innerHTML = `<div><div class="emp-name">${emp.name}</div><div class="emp-code">ID: ${emp.id}</div></div><i class="fas fa-plus-circle" style="color:var(--primary)"></i>`;
                item.onclick = () => {
                    document.getElementById(inputId).value = emp.id;
                    resultsDiv.remove();
                };
                resultsDiv.appendChild(item);
            });
            inputWrapper.appendChild(resultsDiv);
        }

        function addAdjItemFromSearch(type, isPerDay) {
            const searchInput = document.getElementById(`${type}-emp-search`);
            const searchValue = searchInput?.value.trim();
            const employee = allData.find(emp => emp.id === searchValue || emp.name.toLowerCase() === searchValue.toLowerCase());
            if (!employee) { showToast('Select an employee first', 'warning'); return; }
            
            const reason = document.getElementById(`adj-reason-${type}`)?.value || '';
            const dateInput = document.getElementById(`adj-date-${type}`);
            const date = dateInput ? dateInput.value : '';
            const amtInput = document.getElementById(`adj-amt-${type}`);
            let amount = amtInput ? parseFloat(amtInput.value) || 0 : 0;
            
            const modeInput = document.getElementById(`adj-mode-${type}`);
            if (modeInput && modeInput.value === 'deduction') {
                amount = -Math.abs(amount);
            }

            if (type === 'ncns') amount = NCNS_PENALTY;
            else if (type === 'misspunch') amount = MISSPUNCH_DEDUCTION;

            if (!payrollAdj[type][employee.id]) payrollAdj[type][employee.id] = [];
            payrollAdj[type][employee.id].push({ amount, reason, date, addedAt: new Date().toISOString() });
            persistAllAdj();
            showToast(`✅ Entry added for ${employee.name}`, 'success');
            loadAttendanceData();
        }

        // Keep standard helper functions ...
        function deleteAdjItem(type, empId, idx) {
            if (!confirm('Confirm delete?')) return;
            payrollAdj[type][empId].splice(idx, 1);
            if (payrollAdj[type][empId].length === 0) delete payrollAdj[type][empId];
            persistAllAdj();
            showToast('✅ Entry deleted', 'success');
            loadAttendanceData();
        }

        function renderAdvanceTab() {
            let listHtml = '';
            allData.forEach(emp => {
                const adv = payrollAdj.advance[emp.id];
                if (adv) {
                    const remaining = (parseFloat(adv.total)||0) - (parseFloat(adv.paid)||0);
                    listHtml += `<div class="adj-item" style="grid-template-columns:2fr 1fr 1fr 1fr 1fr 0.5fr;">
                        <div class="name">${emp.name} <span style="color:var(--text-muted)">(${emp.id})</span></div>
                        <div>Total: ₨${(parseFloat(adv.total)||0).toLocaleString()}</div>
                        <div>Monthly: ₨${(parseFloat(adv.perMonth)||0).toLocaleString()}</div>
                        <div>Paid: ₨${(parseFloat(adv.paid)||0).toLocaleString()}</div>
                        <div style="color:var(--danger)">Remaining: ₨${remaining.toLocaleString()}</div>
                        <button class="adj-delete" onclick="deleteAdvance('${emp.id}')"><i class="fas fa-trash"></i></button>
                    </div>`;
                }
            });
            if (!listHtml) listHtml = '<div style="text-align:center;padding:30px;color:var(--text-muted);">No advance records.</div>';
            return `
                <div class="adj-section">
                    <h3><i class="fas fa-hand-holding-usd"></i> Advance Salary Management</h3>
                    <div class="adj-form-grid">
                        <div class="search-input-wrapper">
                            <label class="adj-label">Search Employee</label>
                            <input type="text" class="adj-input" id="adv-emp-search" placeholder="Type name or ID..." onkeyup="renderEmployeeSearchResults('adv-emp-search', this.value)">
                        </div>
                        <div><label class="adj-label">Total Advance Amount</label><input type="number" class="adj-input" id="adv-total" placeholder="e.g. 50000"></div>
                        <div><label class="adj-label">Monthly Deduction</label><input type="number" class="adj-input" id="adv-perMonth" placeholder="e.g. 5000"></div>
                        <div><label class="adj-label">Paid So Far</label><input type="number" class="adj-input" id="adv-paid" value="0"></div>
                    </div>
                    <button class="btn btn-primary" onclick="addAdvanceFromSearch()"><i class="fas fa-plus"></i> Set Advance</button>
                    <button class="btn btn-secondary" onclick="triggerCSVUpload('advance')" style="margin-left:8px; background: rgba(255,255,255,0.05); color: white; border: 1px solid var(--border-color);"><i class="fas fa-file-upload"></i> Bulk Upload CSV</button>
                    <a href="#" onclick="downloadCSVTemplate('advance'); return false;" style="margin-left:12px; font-size:12px; color: var(--primary); text-decoration: none; display: inline-block; vertical-align: middle;"><i class="fas fa-download"></i> Template</a>
                    <input type="file" id="csv-file-input-advance" style="display:none;" accept=".csv" onchange="handleCSVFileSelected(event, 'advance')">
                    
                    <div class="adj-list">${listHtml}</div>
                </div>
            `;
        }

        function addAdvanceFromSearch() {
            const searchInput = document.getElementById('adv-emp-search');
            const searchValue = searchInput?.value.trim();
            const employee = allData.find(emp => emp.id === searchValue || emp.name.toLowerCase() === searchValue.toLowerCase());
            if (!employee) { showToast('Select an employee first', 'warning'); return; }
            const total = parseFloat(document.getElementById('adv-total').value) || 0;
            const perMonth = parseFloat(document.getElementById('adv-perMonth').value) || 0;
            const paid = parseFloat(document.getElementById('adv-paid').value) || 0;
            if (total <= 0 || perMonth <= 0) { showToast('Invalid amounts', 'warning'); return; }
            payrollAdj.advance[employee.id] = { total, perMonth, paid, skipMonths: [], addedAt: new Date().toISOString() };
            persistAllAdj();
            showToast(`✅ Advance setup complete for ${employee.name}`, 'success');
            loadAttendanceData();
        }

        function deleteAdvance(empId) {
            if (!confirm('Confirm delete?')) return;
            delete payrollAdj.advance[empId];
            persistAllAdj();
            showToast('✅ Advance deleted', 'success');
            loadAttendanceData();
        }

        function renderManualTab() {
            return `
                <div class="adj-section">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3>Manual Adjustments Overrides</h3>
                        <button class="btn btn-secondary" onclick="showTaxSlabsInfoModal()" style="font-size:11px; padding:6px 12px; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); color:white;"><i class="fas fa-info-circle"></i> FBR Tax Slabs 2026-27 Info</button>
                    </div>
                    <div class="adj-form-grid">
                        <div class="search-input-wrapper">
                            <label class="adj-label">Search Employee</label>
                            <input type="text" class="adj-input" id="ml-emp-search" placeholder="Type name or ID..." onkeyup="renderEmployeeSearchResults('ml-emp-search', this.value)">
                        </div>
                        <div><label class="adj-label">Late Deduction Override (₨)</label><input type="number" class="adj-input" id="ml-amt" placeholder="e.g. 1500"></div>
                        <div><label class="adj-label">Manual Tax Override (₨)</label><input type="number" class="adj-input" id="ml-tax" placeholder="e.g. 2000"></div>
                    </div>
                    <button class="btn btn-primary" onclick="saveManualOverridesFromSearch()"><i class="fas fa-save"></i> Save Overrides</button>
                    <button class="btn btn-secondary" onclick="triggerCSVUpload('manualLate')" style="margin-left:8px; background: rgba(255,255,255,0.05); color: white; border: 1px solid var(--border-color);"><i class="fas fa-file-upload"></i> Bulk Upload CSV</button>
                    <a href="#" onclick="downloadCSVTemplate('manualLate'); return false;" style="margin-left:12px; font-size:12px; color: var(--primary); text-decoration: none; display: inline-block; vertical-align: middle;"><i class="fas fa-download"></i> Template</a>
                    <input type="file" id="csv-file-input-manualLate" style="display:none;" accept=".csv" onchange="handleCSVFileSelected(event, 'manualLate')">
                </div>
            `;
        }

        function saveManualOverridesFromSearch() {
            const searchInput = document.getElementById('ml-emp-search');
            const searchValue = searchInput?.value.trim();
            const employee = allData.find(emp => emp.id === searchValue || emp.name.toLowerCase() === searchValue.toLowerCase());
            if (!employee) { showToast('Select employee first', 'warning'); return; }
            
            const amt = parseFloat(document.getElementById('ml-amt').value) || 0;
            const taxAmt = parseFloat(document.getElementById('ml-tax').value) || 0;
            
            if (amt > 0) payrollAdj.manualLate[employee.id] = amt;
            if (taxAmt > 0) payrollAdj.tax[employee.id] = taxAmt;
            
            persistAllAdj();
            showToast('✅ Overrides saved successfully', 'success');
            loadAttendanceData();
        }

        function renderSettingsTab() {
            let listHtml = '<table><thead><tr><th>ID</th><th>Name</th><th>Basic Salary</th><th>Punctuality Eligible</th><th>Punctuality Bonus (₨)</th><th>Action</th></tr></thead><tbody>';
            allData.forEach(emp => {
                const meta = getEmpMeta(emp.id);
                listHtml += `<tr>
                    <td>${emp.id}</td>
                    <td><strong>${emp.name}</strong></td>
                    <td><input type="number" class="adj-input" id="set-bs-${emp.id}" value="${meta.basicSalary}" style="width:140px;"></td>
                    <td><input type="checkbox" id="set-punc-${emp.id}" ${meta.punctualityEnabled ? 'checked' : ''}></td>
                    <td><input type="number" class="adj-input" id="set-punc-amt-${emp.id}" value="${meta.punctualityAmount ?? PERFECT_ATTENDANCE_BONUS}" style="width:120px;"></td>
                    <td><button class="btn btn-success" style="padding:6px 12px; font-size:11px;" onclick="saveEmpSettings('${emp.id}')"><i class="fas fa-save"></i> Save</button></td>
                </tr>`;
            });
            listHtml += '</tbody></table>';
            return `
                <div class="adj-section">
                    <h3>Employee Settings Config</h3>
                    <div class="table-container">${listHtml}</div>
                </div>
            `;
        }

        function saveEmpSettings(empId) {
            const bs = parseFloat(document.getElementById(`set-bs-${empId}`).value) || BASE_SALARY;
            const punc = document.getElementById(`set-punc-${empId}`).checked;
            const puncAmt = parseFloat(document.getElementById(`set-punc-amt-${empId}`).value) || PERFECT_ATTENDANCE_BONUS;
            const meta = getEmpMeta(empId);
            meta.basicSalary = bs;
            meta.punctualityEnabled = punc;
            meta.punctualityAmount = puncAmt;
            payrollAdj.empMeta[empId] = meta;
            persistAllAdj();
            showToast('✅ Employee settings saved', 'success');
            loadAttendanceData();
        }

        // ===== EXPORTS =====
        function exportPayrollCSV() {
            const payrollData = allData.map(emp => calculatePayrollForEmployee(emp));
            const headers = ['ID','Name','Designation','Basic Salary','Punctuality Bonus','Total Salary','Per Day Salary','Working Days','Presents','Leaves','Absents','Gross Salary','Status'];
            const csvCell = value => `"${String(value ?? '').replaceAll('"', '""')}"`;
            let csv = headers.map(csvCell).join(',') + '\n';
            payrollData.forEach(e => {
                const row = [e.id, e.name, e.designation, e.basicSalary, e.punctualityBonus, e.totalSalary, Math.round(e.perDaySalary), workingDaysCount, e.present, e.adjustedLeaveCount, e.adjustedAbsent, Math.round(e.grossSalary), e.status];
                csv += row.map(csvCell).join(',') + '\n';
            });
            downloadCSV(csv, `payroll_${currentYear}_${currentMonth}.csv`);
            showToast('✅ Payroll exported successfully', 'success');
        }

        function exportBankTransferCSV() {
            const payrollData = allData.map(emp => calculatePayrollForEmployee(emp));
            const headers = ['Employee Code', 'Employee Name', 'Bank Name', 'Account Title', 'Account Number', 'Net Salary Payable (PKR)'];
            const csvCell = value => `"${String(value ?? '').replaceAll('"', '""')}"`;
            let csv = headers.map(csvCell).join(',') + '\n';
            payrollData.forEach(e => {
                const empRemarks = e.remarks || (payrollAdj.remarks && payrollAdj.remarks[e.id]) || 'Ready for Payment';
                if (empRemarks === 'Hold') {
                    return;
                }
                const row = [
                    e.id, 
                    e.name, 
                    e.bankName || 'Not Set', 
                    e.accountTitle || 'Not Set', 
                    e.accountNo || 'Not Set', 
                    Math.round(e.finalNetSalary)
                ];
                csv += row.map(csvCell).join(',') + '\n';
            });
            downloadCSV(csv, `bank_transfer_${currentYear}_${currentMonth}.csv`);
            showToast('✅ Bank transfer sheet exported successfully', 'success');
        }

        function showTaxSlabsInfoModal() {
            let modal = document.getElementById('taxSlabsModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'taxSlabsModal';
                modal.className = 'modal';
                document.body.appendChild(modal);
            }
            
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 600px; color: var(--text-color);">
                    <div class="modal-header">
                        <h2>FBR Salary Tax Slabs (2026-27)</h2>
                        <div class="modal-close" onclick="document.getElementById('taxSlabsModal').classList.remove('active')">&times;</div>
                    </div>
                    <div class="modal-body" style="padding: 20px;">
                        <p style="margin-bottom: 16px; font-size:13px; color:var(--text-muted);">
                            This progressive tax slab calculation is automatically applied to the annual taxable income (Base Salary × 12). The monthly tax is deduced as Annual Tax ÷ 12.
                        </p>
                        <table style="width:100%; border-collapse:collapse; font-size:12px;">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                                    <th style="padding: 8px;">Annual Taxable Income (PKR)</th>
                                    <th style="padding: 8px;">Formula for Annual Tax</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                    <td style="padding: 8px;">Up to 600,000</td>
                                    <td style="padding: 8px; color: var(--secondary);">0 (No Tax)</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                    <td style="padding: 8px;">600,001 – 1,200,000</td>
                                    <td style="padding: 8px;">(Income - 600k) × 1%</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                    <td style="padding: 8px;">1,200,001 – 2,200,000</td>
                                    <td style="padding: 8px;">6,000 + (Income - 1.2M) × 11%</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                    <td style="padding: 8px;">2,200,001 – 3,200,000</td>
                                    <td style="padding: 8px;">116,000 + (Income - 2.2M) × 20%</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                    <td style="padding: 8px;">3,200,001 – 4,100,000</td>
                                    <td style="padding: 8px;">316,000 + (Income - 3.2M) × 25%</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                    <td style="padding: 8px;">4,100,001 – 5,600,000</td>
                                    <td style="padding: 8px;">541,000 + (Income - 4.1M) × 29%</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                    <td style="padding: 8px;">5,600,001 – 7,000,000</td>
                                    <td style="padding: 8px;">976,000 + (Income - 5.6M) × 32%</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px;">Above 7,000,000</td>
                                    <td style="padding: 8px;">1,424,000 + (Income - 7.0M) × 35%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-footer" style="padding:15px; text-align:right;">
                        <button class="btn btn-primary" onclick="document.getElementById('taxSlabsModal').classList.remove('active')">Dismiss</button>
                    </div>
                </div>
            `;
            modal.classList.add('active');
        }

        function exportToCSV() {
            let headers = ['ID', 'Personnel', 'Department', 'Designation', 'Branch', 'Team'];
            for (let day = 1; day <= daysInMonth; day++) headers.push(`${day} ${getMonthAbbr(currentMonth)}`);
            headers.push('Present Days', 'Absent Days', 'Late Days', 'Leave Days');
            let csvContent = headers.map(h => `"${h}"`).join(',') + '\n';
            allData.forEach(emp => { 
                let row = [emp.id, emp.name, emp.department, emp.designation, emp.branch, emp.team];
                for (let day = 1; day <= daysInMonth; day++) { 
                    row.push(emp.attendance[day]); 
                }
                row.push(emp.present, emp.absent, emp.late, emp.leave);
                csvContent += row.map(cell => `"${cell}"`).join(',') + '\n';
            });
            downloadCSV(csvContent, `attendance_${currentYear}_${currentMonth}.csv`);
            showToast(`✅ Exported successfully`, 'success');
        }

        function downloadCSV(content, filename) { const blob = new Blob(["\uFEFF" + content], { type: 'text/csv;charset=utf-8;' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = filename; a.click(); URL.revokeObjectURL(url); }
        function viewPayrollSlip(employeeId, event) {
            if (event) event.stopPropagation();
            const employee = allData.find(e => e.id === employeeId);
            if (!employee) return;
            const e = calculatePayrollForEmployee(employee);
            const formatMoney = val => '₨ ' + Math.round(val || 0).toLocaleString();

            const finalNetSalary = Math.round(e.netSalary - e.tax);
            const totalDeductionsAll = Math.round(e.totalDeductions + e.absentDeduction);

            const slipHtml = `
                <div id="printArea" style="position: relative; overflow: hidden; padding: 40px; background: #ffffff; color: #0f172a; font-family: 'Plus Jakarta Sans', sans-serif;">
                    
                    <!-- Watermark -->
                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); opacity: 0.03; pointer-events: none; z-index: 1;">
                        <img src="assets/images/balitech-logo.png" style="width: 450px; filter: grayscale(100%);">
                    </div>

                    <!-- Header -->
                    <div style="background: #0f172a; color: white; padding: 25px 30px; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center; position: relative; z-index: 2;">
                        <div>
                            <h2 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; color: white;">BALITECH</h2>
                            <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 14px; font-weight: 500;">Monthly Salary Slip</p>
                        </div>
                        <div style="background: white; color: #0f172a; padding: 8px 16px; border-radius: 30px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                            MONTH: ${['January','February','March','April','May','June','July','August','September','October','November','December'][currentMonth-1].toUpperCase()}, ${currentYear}
                        </div>
                    </div>

                    <!-- Employee Quick Info -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #f8fafc; padding: 20px; border-radius: 0 0 16px 16px; border: 1px solid #e2e8f0; border-top: none; margin-bottom: 30px; position: relative; z-index: 2;">
                        <div style="display: flex; flex-direction: column;">
                            <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 5px;">Employee Name</span>
                            <span style="font-size: 18px; font-weight: 700; color: #0f172a;">${employee.name} (${employee.id})</span>
                        </div>
                        <div style="display: flex; flex-direction: column; text-align: right;">
                            <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 5px;">Department / Designation</span>
                            <span style="font-size: 18px; font-weight: 700; color: #0f172a;">${employee.department || '—'} / ${employee.designation || '—'}</span>
                        </div>
                    </div>
                    
                    <!-- Salary & Attendance Summary -->
                    <div style="margin-bottom: 30px; position: relative; z-index: 2;">
                        <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Salary & Attendance Summary</h3>
                        <table style="width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; font-size: 13px;">
                            <tbody>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Basic Salary</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.basicSalary)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Punctuality Amount</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.punctualityBonus)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Present Days</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${e.present}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Absent Days</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${e.adjustedAbsent}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Paid Leave</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${e.adjustedLeaveCount}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Half Day count</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${e.halfDayCount}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">No. of NCNS</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${e.ncnsCount}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">No. of Sandwich Docks</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${e.sdCount}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">No. of Late Arrivals</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${e.late}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Arrears / Bonus / Allowances</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.arrears + e.bonus + e.tada + e.punctualityAmount)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Extra Day Pay</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.extraDayPay)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #0f172a; background: #f8fafc; border-top: 2px solid #cbd5e1;"><td style="text-align:left; padding:10px 15px; color:#0f172a !important;">Total Earnings / Gross Salary</td><td style="text-align:right; padding:10px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.totalEarnings)}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Specified Deductions -->
                    <div style="margin-bottom: 30px; position: relative; z-index: 2;">
                        <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Specified Deductions</h3>
                        <table style="width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; font-size: 13px;">
                            <tbody>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Absenteeism Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.absentDeduction)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Late Coming Dock</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.lateDeduction)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Half Day Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.halfDayAmount)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Sandwich Dock Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.sdAmount)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">NCNS Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.ncnsAmount)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">QA / HR Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.qaHrAmount)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Misspunch Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.misspunchAmount)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Tax Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.tax)}</td></tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;"><td style="text-align:left; padding:8px 15px; color:#1e293b !important;">Advance Salary Deduction</td><td style="text-align:right; padding:8px 15px; font-weight:700; color:#0f172a !important;">${formatMoney(e.advanceDeduction)}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Highlight Boxes -->
                    <div style="background: #f8fafc; border-radius: 12px; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border: 1px solid #e2e8f0; position: relative; z-index: 2;">
                        <span style="font-size: 16px; font-weight: 700; color: #334155;">Total Deduction</span>
                        <span style="font-size: 20px; font-weight: 800; color: #ef4444;">${formatMoney(totalDeductionsAll)}</span>
                    </div>

                    <div style="background: #f0fdf4; border-radius: 12px; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #bbf7d0; position: relative; z-index: 2; margin-bottom: 25px;">
                        <span style="font-size: 18px; font-weight: 700; color: #166534;">Net Payable Salary</span>
                        <span style="font-size: 24px; font-weight: 800; color: #15803d;">${formatMoney(finalNetSalary)}</span>
                    </div>

                    <!-- Note Footer -->
                    <div style="font-size: 11px; color: #64748b; line-height: 1.6; text-align: justify; border-top: 1px solid #e2e8f0; padding-top: 15px; position: relative; z-index: 2;">
                        <strong>Note:</strong> Traveling allowance and transport deduction apply only for females where approved/applicable. NCNS, sandwich dock, late arrival, misthumb, half day, and paid leave counts are shown in the summary; deduction amounts are listed separately below as per policy.
                    </div>
                </div>
            `;

            const slipModal = document.createElement('div');
            slipModal.className = 'modal active';
            slipModal.innerHTML = `<div class="modal-content payslip-modal-content">
                <div class="modal-header" style="background:#f1f5f9; border-bottom:1px solid #cbd5e1; flex-shrink:0;">
                    <h2 style="color:#0f172a;"><i class="fas fa-file-invoice"></i> Payslip - ${employee.name}</h2>
                    <div class="modal-close" style="color:#0f172a;" onclick="this.closest('.modal').remove()">&times;</div>
                </div>
                <div class="modal-body payslip-scroll-container" style="padding:0; background:#fff; flex:1;">${slipHtml}</div>
                <div class="slip-actions" style="flex-shrink:0; border-top:1px solid #cbd5e1;">
                    <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    <button class="btn btn-primary" onclick="this.closest('.modal').remove()">Close</button>
                </div>
            </div>`;
            document.body.appendChild(slipModal);
        }

        function viewEmployeeDetails(employeeId, employeeName) {
            const modal = document.getElementById('employeeModal');
            const modalBody = document.getElementById('modalBody');
            const modalName = document.getElementById('modalEmployeeName');
            const employee = allData.find(e => e.id === employeeId);
            if (!employee) return;

            modalName.textContent = employeeName;
            modal.classList.add('active');

            const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            let leavesHtml = employee.leaves && employee.leaves.length > 0 ? employee.leaves.map(leave => `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(255,255,255,0.03);border-radius:10px;margin-bottom:8px;"><div><div>📅 ${new Date(leave.date).toLocaleDateString()}</div><div style="font-size:11px;color:var(--text-muted);">${leave.reason}</div></div><span class="summary-badge summary-leave">${leave.type}</span></div>`).join('') : '<div style="text-align:center;padding:20px;color:var(--text-muted);">No leaves recorded</div>';

            let tableHtml = `
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
                <div style="background:rgba(255,255,255,0.03);padding:12px;border-radius:10px;text-align:center;"><div style="font-size:18px;font-weight:700;color:var(--secondary);">${employee.present}</div><div style="font-size:10px;color:var(--text-muted)">Present</div></div>
                <div style="background:rgba(255,255,255,0.03);padding:12px;border-radius:10px;text-align:center;"><div style="font-size:18px;font-weight:700;color:var(--danger);">${employee.absent}</div><div style="font-size:10px;color:var(--text-muted)">Absent</div></div>
                <div style="background:rgba(255,255,255,0.03);padding:12px;border-radius:10px;text-align:center;"><div style="font-size:18px;font-weight:700;color:var(--warning);">${employee.late}</div><div style="font-size:10px;color:var(--text-muted)">Late</div></div>
                <div style="background:rgba(255,255,255,0.03);padding:12px;border-radius:10px;text-align:center;"><div style="font-size:18px;font-weight:700;color:var(--info);">${employee.leave}</div><div style="font-size:10px;color:var(--text-muted)">Leave</div></div>
            </div>
            
            <div style="margin-bottom:20px;">
                <h4 style="font-size:13px;color:white;margin-bottom:12px;">Leaves History</h4>
                <div>${leavesHtml}</div>
            </div>
            
            <div class="table-container">
            <div class="table-wrapper">
            <table>
                <thead><tr><th>Date</th><th>Day</th><th>Check In</th><th>Status</th></tr></thead>
                <tbody>`;
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(currentYear, currentMonth - 1, day);
                const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
                const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                const checkin = employee.attendance[day];
                const isPresent = checkin !== '--:--';
                const isLate = isPresent && isCheckinLate(checkin);
                const hasLeave = employee.paidLeaveDates.includes(`${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`);
                let status = 'Absent', statusClass = 'summary-absent';
                if (isWeekendDay) { status = isPresent ? 'Present (Weekend)' : 'Weekend'; statusClass = 'summary-leave'; }
                else if (hasLeave) { status = 'On Leave'; statusClass = 'summary-leave'; }
                else if (isPresent) { status = isLate ? 'Late' : 'Present'; statusClass = isLate ? 'summary-late' : 'summary-present'; }
                tableHtml += `<tr><td>${day} ${getMonthAbbr(currentMonth)}</td><td>${dayName}</td><td>${checkin}</td><td><span class="summary-badge ${statusClass}">${status}</span></td></tr>`;
            }
            tableHtml += `</tbody></table></div></div>`;
            modalBody.innerHTML = tableHtml;
        }

        function closeModal(id = 'employeeModal') {
            document.getElementById(id)?.classList.remove('active');
        }

        function filterByTeamLead() {
            renderTable();
        }

        function showToast(message, type) { 
            const container = document.getElementById('toastContainer'); 
            const toast = document.createElement('div'); 
            toast.className = 'toast show'; 
            toast.style.borderLeftColor = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b'; 
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${message}`; 
            container.appendChild(toast); 
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000); 
        }

        function updateDateTime() { 
            const now = new Date(); 
            document.getElementById('currentDate').querySelector('span').textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }); 
        }

        document.getElementById('searchInput').addEventListener('keyup', function() { renderTable(); });
        document.getElementById('departmentFilter').addEventListener('change', function() { renderTable(); });

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Initialize
        updateDateTime();
        setInterval(updateDateTime, 1000);
        
        async function initPage() {
            await loadEmployeeList();
            await loadAttendanceData();
        }
        
        async function loadEmployeeList() {
            try {
                const response = await fetch(API_BASE + 'attendance-api.php?action=getFilterOptions');
                const data = await response.json();
                if (data.success && data.data && data.data.departments) {
                    const deptSelect = document.getElementById('departmentFilter');
                    deptSelect.innerHTML = '<option value="">All Departments</option>';
                    data.data.departments.forEach(dept => { deptSelect.innerHTML += `<option value="${dept}">${dept}</option>`; });
                }
            } catch(e) { console.log('Filter load fallback'); }
        }

        function downloadPayrollCSVTemplate() {
            const csvContent = "Biometric ID,Name,Basic Salary,Punctuality Amount,Appointment Date,Bank Name,Account No,Account Title,CNIC,Contact No\n101,John Doe,55000,5000,2026-05-01,Meezan Bank,01020304050607,John Doe,12345-6789012-3,03001234567\n102,Jane Smith,60000,5000,2026-06-15,Bank Alfalah,98765432109876,Jane Smith,35202-1234567-8,03219876543\n";
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("download", "payroll_users_import_template.csv");
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // ===== BULK CSV IMPORT SYSTEM =====
        function downloadCSVTemplate(type, isPerDay) {
            let csvContent = "";
            if (type === 'advance') {
                csvContent = "BiometricID,Total,PerMonth,Paid\n1012,50000,5000,0\n";
            } else if (type === 'manualLate') {
                csvContent = "BiometricID,Amount\n1012,1500\n";
            } else if (isPerDay) {
                if (['halfDay','sd','ncns','misspunch'].includes(type)) {
                    csvContent = "BiometricID,Date,Description\n1012,2026-07-15,Reason here\n";
                } else {
                    csvContent = "BiometricID,Amount,Date,Description\n1012,2500,2026-07-15,Reason here\n";
                }
            } else {
                csvContent = "BiometricID,Amount,Description\n1012,5000,Reason here\n";
            }
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("download", `template_${type}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function triggerCSVUpload(type) {
            const input = document.getElementById(`csv-file-input-${type}`);
            if (input) {
                input.value = "";
                input.click();
            }
        }

        async function handleCSVFileSelected(event, type, isPerDay) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                const text = e.target.result;
                processCSVData(text, type, isPerDay);
            };
            reader.readAsText(file);
        }

        function parseCSVText(text) {
            const lines = [];
            let row = [""];
            let inQuotes = false;
            for (let i = 0; i < text.length; i++) {
                const char = text[i];
                const nextChar = text[i+1];
                if (char === '"') {
                    if (inQuotes && nextChar === '"') {
                        row[row.length - 1] += '"';
                        i++;
                    } else {
                        inQuotes = !inQuotes;
                    }
                } else if (char === ',' && !inQuotes) {
                    row.push("");
                } else if ((char === '\r' || char === '\n') && !inQuotes) {
                    if (char === '\r' && nextChar === '\n') {
                        i++;
                    }
                    lines.push(row);
                    row = [""];
                } else {
                    row[row.length - 1] += char;
                }
            }
            if (row.length > 1 || row[0] !== "") {
                lines.push(row);
            }
            return lines;
        }

        function processCSVData(text, type, isPerDay) {
            const parsedLines = parseCSVText(text);
            if (parsedLines.length < 2) {
                showToast("CSV file is empty or missing headers", "error");
                return;
            }
            
            const headers = parsedLines[0].map(h => h.trim().toLowerCase());
            const rows = parsedLines.slice(1);
            
            const bidIdx = headers.findIndex(h => h.includes("biometric") || h === "id" || h === "code");
            const amtIdx = headers.findIndex(h => h.includes("amount") || h === "amt");
            const dateIdx = headers.findIndex(h => h.includes("date"));
            const descIdx = headers.findIndex(h => h.includes("description") || h.includes("reason") || h === "comments");
            const totalIdx = headers.findIndex(h === "total");
            const perMonthIdx = headers.findIndex(h.includes("permonth") || h.includes("per_month"));
            const paidIdx = headers.findIndex(h === "paid");

            if (bidIdx === -1) {
                showToast("Missing 'BiometricID' header in CSV", "error");
                return;
            }

            const importQueue = [];
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                if (row.length === 1 && row[0] === "") continue;
                const rawBid = row[bidIdx] ? row[bidIdx].trim() : "";
                if (!rawBid) continue;
                
                const employee = allData.find(emp => String(emp.id) === String(rawBid));
                const empName = employee ? employee.name : "Unknown Employee (Invalid Biometric ID)";
                const isValid = !!employee;
                
                let amount = 0;
                let date = "";
                let description = "";
                let total = 0;
                let perMonth = 0;
                let paid = 0;
                
                if (type === 'advance') {
                    total = totalIdx !== -1 && row[totalIdx] ? parseFloat(row[totalIdx]) || 0 : 0;
                    perMonth = perMonthIdx !== -1 && row[perMonthIdx] ? parseFloat(row[perMonthIdx]) || 0 : 0;
                    paid = paidIdx !== -1 && row[paidIdx] ? parseFloat(row[paidIdx]) || 0 : 0;
                    description = descIdx !== -1 && row[descIdx] ? row[descIdx].trim() : "Bulk Advance";
                } else if (type === 'manualLate') {
                    amount = amtIdx !== -1 && row[amtIdx] ? parseFloat(row[amtIdx]) || 0 : 0;
                } else {
                    if (amtIdx !== -1 && row[amtIdx]) {
                        amount = parseFloat(row[amtIdx]) || 0;
                    } else if (type === 'ncns') {
                        amount = NCNS_PENALTY;
                    } else if (type === 'misspunch') {
                        amount = MISSPUNCH_DEDUCTION;
                    }
                    date = dateIdx !== -1 && row[dateIdx] ? row[dateIdx].trim() : `${currentYear}-${String(currentMonth).padStart(2,'0')}-01`;
                    description = descIdx !== -1 && row[descIdx] ? row[descIdx].trim() : "Bulk Adjustment";
                }

                importQueue.push({
                    biometricId: rawBid,
                    employeeName: empName,
                    amount,
                    date,
                    description,
                    total,
                    perMonth,
                    paid,
                    isValid
                });
            }

            if (importQueue.length === 0) {
                showToast("No valid rows found to import", "warning");
                return;
            }

            showCSVPreviewModal(importQueue, type, isPerDay);
        }

        function showCSVPreviewModal(queue, type, isPerDay) {
            window.pendingCSVQueue = queue.filter(r => r.isValid);
            let modal = document.getElementById('csvPreviewModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'csvPreviewModal';
                modal.className = 'modal';
                document.body.appendChild(modal);
            }
            const validCount = window.pendingCSVQueue.length;
            const invalidCount = queue.length - validCount;
            let tableRows = queue.map(r => `
                <tr style="${r.isValid ? '' : 'background: rgba(239, 68, 68, 0.1); color: var(--danger);'}">
                    <td style="padding: 8px;">${r.isValid ? '✅' : '❌'}</td>
                    <td style="padding: 8px;"><strong>${escapeHtml(r.biometricId)}</strong></td>
                    <td style="padding: 8px;">${escapeHtml(r.employeeName)}</td>
                    ${type === 'advance' ? `
                        <td style="padding: 8px;">₨${r.total.toLocaleString()}</td>
                        <td style="padding: 8px;">₨${r.perMonth.toLocaleString()}</td>
                        <td style="padding: 8px;">₨${r.paid.toLocaleString()}</td>
                    ` : `
                        <td style="padding: 8px;">${type === 'manualLate' ? `₨${r.amount.toLocaleString()}` : (['halfDay','sd','ncns','misspunch'].includes(type) ? '—' : `₨${r.amount.toLocaleString()}`)}</td>
                        <td style="padding: 8px;">${escapeHtml(r.date || '—')}</td>
                        <td style="padding: 8px;">${escapeHtml(r.description || '—')}</td>
                    `}
                </tr>
            `).join('');

            modal.innerHTML = `
                <div class="modal-content" style="max-width: 800px; max-height: 80vh; display: flex; flex-direction: column;">
                    <div class="modal-header">
                        <h2>Confirm CSV Import (${type.toUpperCase()})</h2>
                        <div class="modal-close" onclick="closeCSVPreviewModal()">&times;</div>
                    </div>
                    <div style="padding: 16px 24px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border-color); display: flex; gap: 20px;">
                        <div>Total Rows: <strong>${queue.length}</strong></div>
                        <div style="color: var(--secondary);">Valid: <strong>${validCount}</strong></div>
                        <div style="color: var(--danger);">Invalid/Warning: <strong>${invalidCount}</strong></div>
                    </div>
                    <div style="flex: 1; overflow-y: auto; padding: 20px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                                    <th style="padding: 8px;">Status</th>
                                    <th style="padding: 8px;">Biometric ID</th>
                                    <th style="padding: 8px;">Name</th>
                                    ${type === 'advance' ? `
                                        <th style="padding: 8px;">Total</th>
                                        <th style="padding: 8px;">Per Month</th>
                                        <th style="padding: 8px;">Paid</th>
                                    ` : `
                                        <th style="padding: 8px;">Amount</th>
                                        <th style="padding: 8px;">Date</th>
                                        <th style="padding: 8px;">Description</th>
                                    `}
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows}
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color); text-align: right; display: flex; justify-content: flex-end; gap: 12px;">
                        <button class="btn btn-secondary" onclick="closeCSVPreviewModal()">Cancel</button>
                        <button class="btn btn-primary" onclick="confirmCSVImport('${type}')" ${validCount === 0 ? 'disabled' : ''}>
                            <i class="fas fa-file-import"></i> Import ${validCount} Entries
                        </button>
                    </div>
                </div>
            `;
            modal.classList.add('active');
        }

        function closeCSVPreviewModal() {
            const modal = document.getElementById('csvPreviewModal');
            if (modal) modal.classList.remove('active');
        }

        function confirmCSVImport(type) {
            const queue = window.pendingCSVQueue || [];
            if (queue.length === 0) return;
            queue.forEach(r => {
                if (type === 'advance') {
                    payrollAdj.advance[r.biometricId] = {
                        total: r.total,
                        perMonth: r.perMonth,
                        paid: r.paid,
                        skipMonths: [],
                        addedAt: new Date().toISOString()
                    };
                } else if (type === 'manualLate') {
                    payrollAdj.manualLate[r.biometricId] = r.amount;
                } else {
                    if (!payrollAdj[type][r.biometricId]) {
                        payrollAdj[type][r.biometricId] = [];
                    }
                    payrollAdj[type][r.biometricId].push({
                        amount: r.amount,
                        reason: r.description,
                        date: r.date,
                        addedAt: new Date().toISOString()
                    });
                }
            });
            persistAllAdj();
            showToast(`✅ Successfully imported ${queue.length} entries!`, 'success');
            closeCSVPreviewModal();
            loadAttendanceData();
        }

        // ═══════════════════════════════════════════════════════════════════
        // USER MANAGEMENT (FINANCE USER SETTINGS) MODULE
        // ═══════════════════════════════════════════════════════════════════
        let financeUsersData = [];

        async function loadFinanceUsers() {
            const tbody = document.getElementById('financeUsersTableBody');
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="9"><div class="fum-empty"><div class="loading-spinner"></div><p style="margin-top:10px;">Loading users...</p></div></td></tr>';
            try {
                const res = await fetch('api/payroll_api.php?action=getFinanceUsers');
                const data = await res.json();
                if (data.success && data.data) {
                    financeUsersData = data.data;
                    renderFinanceUsersTable(financeUsersData);
                } else {
                    tbody.innerHTML = '<tr><td colspan="9"><div class="fum-empty"><p style="color:var(--danger);">Failed to load employees.</p></div></td></tr>';
                }
            } catch(e) {
                console.error(e);
                tbody.innerHTML = '<tr><td colspan="9"><div class="fum-empty"><p style="color:var(--danger);">Connection error loading employees.</p></div></td></tr>';
            }
        }

        function renderFinanceUsersTable(users) {
            const tbody = document.getElementById('financeUsersTableBody');
            if (!tbody) return;
            if (!users || users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9"><div class="fum-empty"><p>No employees found.</p></div></td></tr>';
                return;
            }
            tbody.innerHTML = users.map(u => {
                const initials = u.full_name ? u.full_name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2) : '??';
                const apptStr = u.appointment_date ? u.appointment_date : '<span style="color:var(--text-muted); font-style:italic;">Not set</span>';
                
                // Calculate 60-day eligibility indicator badge
                let apptBadge = apptStr;
                if (u.appointment_date) {
                    const appt = new Date(u.appointment_date);
                    const now = new Date();
                    const diffDays = Math.floor((now - appt) / (1000 * 60 * 60 * 24));
                    if (diffDays >= 60) {
                        apptBadge = `<span>${u.appointment_date}</span> <span style="font-size:10px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); padding:2px 6px; border-radius:10px; margin-left:4px;">>= 60 Days</span>`;
                    } else {
                        apptBadge = `<span>${u.appointment_date}</span> <span style="font-size:10px; background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); padding:2px 6px; border-radius:10px; margin-left:4px;">${diffDays} Days (<60)</span>`;
                    }
                }

                return `
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:34px;height:34px;background:rgba(249,115,22,0.15);border:1px solid rgba(249,115,22,0.3);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-weight:700;font-size:12px;">${initials}</div>
                                <div><strong style="color:#fff;">${escapeHtml(u.full_name)}</strong></div>
                            </div>
                        </td>
                        <td><span style="font-weight:700; font-family:monospace;">${escapeHtml(u.employee_code)}</span></td>
                        <td><span style="color:var(--text-muted); font-size:12px;">${escapeHtml(u.email || '—')}</span></td>
                        <td><span style="color:#38bdf8; font-size:12px; font-weight:600;">${escapeHtml(u.contact_no || '—')}</span></td>
                        <td><span style="background:rgba(255,255,255,0.04); border:1px solid var(--border-color); padding:3px 8px; border-radius:12px; font-size:11px;">${escapeHtml(u.department || 'General')}</span></td>
                        <td><span style="background:rgba(99,102,241,0.12); border:1px solid rgba(99,102,241,0.3); color:#818cf8; padding:3px 8px; border-radius:12px; font-size:11px;">${escapeHtml(u.designation || 'Staff')}</span></td>
                        <td><span style="color:#f8fafc; font-size:12px;">${escapeHtml(u.bank_name || '—')}</span></td>
                        <td><span style="font-family:monospace; color:#34d399; font-size:12px;">${escapeHtml(u.account_no || '—')}</span></td>
                        <td><span style="color:#cbd5e1; font-size:12px;">${escapeHtml(u.account_title || '—')}</span></td>
                        <td><span style="font-family:monospace; color:#fbbf24; font-size:12px;">${escapeHtml(u.cnic || '—')}</span></td>
                        <td>${apptBadge}</td>
                        <td style="font-weight:700; color:#fff;">₨${Number(u.basic_salary).toLocaleString()}</td>
                        <td style="text-align:right; font-weight:700; color:#10b981;">₨${Number(u.punctuality_amount).toLocaleString()}</td>
                        <td style="text-align:center;">
                            <button class="btn btn-secondary" onclick="openFinanceUserEditModal('${u.employee_code}')" style="padding:5px 12px; font-size:11px; background:rgba(249,115,22,0.15); color:var(--primary); border:1px solid rgba(249,115,22,0.3);"><i class="fas fa-edit"></i> Edit</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function filterFinanceUsers() {
            const query = (document.getElementById('financeUserSearch').value || '').toLowerCase().trim();
            if (!query) {
                renderFinanceUsersTable(financeUsersData);
                return;
            }
            const filtered = financeUsersData.filter(u => 
                (u.full_name || '').toLowerCase().includes(query) ||
                (u.employee_code || '').toLowerCase().includes(query) ||
                (u.department || '').toLowerCase().includes(query) ||
                (u.designation || '').toLowerCase().includes(query) ||
                (u.bank_name || '').toLowerCase().includes(query) ||
                (u.account_no || '').toLowerCase().includes(query) ||
                (u.account_title || '').toLowerCase().includes(query) ||
                (u.cnic || '').toLowerCase().includes(query) ||
                (u.contact_no || '').toLowerCase().includes(query)
            );
            renderFinanceUsersTable(filtered);
        }

        function openFinanceUserEditModal(empCode) {
            const u = financeUsersData.find(user => String(user.employee_code) === String(empCode));
            if (!u) return;

            document.getElementById('fe_employee_code').value = u.employee_code;
            document.getElementById('fe_view_code').textContent = u.employee_code;
            document.getElementById('fe_view_name').textContent = u.full_name;
            document.getElementById('fe_view_department').textContent = u.department || '—';
            document.getElementById('fe_view_designation').textContent = u.designation || '—';
            document.getElementById('fe_basic_salary').value = u.basic_salary || 50000;
            document.getElementById('fe_punctuality_amount').value = u.punctuality_amount || 5000;
            document.getElementById('fe_appointment_date').value = u.appointment_date || '';

            document.getElementById('fe_bank_name').value = u.bank_name || '';
            document.getElementById('fe_account_no').value = u.account_no || '';
            document.getElementById('fe_account_title').value = u.account_title || '';
            document.getElementById('fe_cnic').value = u.cnic || '';
            document.getElementById('fe_contact_no').value = u.contact_no || '';

            document.getElementById('feModalName').textContent = u.full_name;
            document.getElementById('feModalAvatar').textContent = u.full_name ? u.full_name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2) : '??';

            document.getElementById('financeUserEditModal').classList.add('active');
        }

        async function saveFinanceUserSettings(event) {
            event.preventDefault();
            const empCode = document.getElementById('fe_employee_code').value;
            const salary = document.getElementById('fe_basic_salary').value;
            const puncAmt = document.getElementById('fe_punctuality_amount').value;
            const apptDate = document.getElementById('fe_appointment_date').value;

            const bankName = document.getElementById('fe_bank_name').value;
            const accountNo = document.getElementById('fe_account_no').value;
            const accountTitle = document.getElementById('fe_account_title').value;
            const cnicVal = document.getElementById('fe_cnic').value;
            const contactNo = document.getElementById('fe_contact_no').value;

            try {
                const res = await fetch('api/payroll_api.php?action=updateFinanceUser', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        employee_code: empCode,
                        basic_salary: parseFloat(salary),
                        punctuality_enabled: 1,
                        punctuality_amount: parseFloat(puncAmt),
                        appointment_date: apptDate,
                        bank_name: bankName,
                        account_no: accountNo,
                        account_title: accountTitle,
                        cnic: cnicVal,
                        contact_no: contactNo
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('✅ Employee settings updated successfully!', 'success');
                    closeModal('financeUserEditModal');
                    await loadFinanceUsers();
                    await initPage();
                } else {
                    showToast('❌ ' + (data.error || 'Failed to save settings'), 'error');
                }
            } catch(e) {
                console.error(e);
                showToast('❌ Connection error saving settings', 'error');
            }
        }

        function openBulkImportModal() {
            const fileInput = document.getElementById('csvFileInput');
            if (fileInput) fileInput.value = '';
            const fileNameSpan = document.getElementById('selectedFileName');
            if (fileNameSpan) { fileNameSpan.style.display = 'none'; fileNameSpan.textContent = ''; }
            const dash = document.getElementById('importResultDashboard');
            if (dash) dash.style.display = 'none';

            const dragZone = document.getElementById('csvDragZone');
            if (dragZone && !dragZone.dataset.bound) {
                dragZone.dataset.bound = 'true';
                dragZone.addEventListener('click', () => fileInput.click());
                dragZone.addEventListener('dragover', (e) => { e.preventDefault(); dragZone.style.borderColor = '#10b981'; });
                dragZone.addEventListener('dragleave', () => { dragZone.style.borderColor = 'rgba(255,255,255,0.15)'; });
                dragZone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dragZone.style.borderColor = 'rgba(255,255,255,0.15)';
                    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        fileInput.files = e.dataTransfer.files;
                        handleBulkCSVFileChange();
                    }
                });
            }

            if (fileInput && !fileInput.dataset.bound) {
                fileInput.dataset.bound = 'true';
                fileInput.addEventListener('change', handleBulkCSVFileChange);
            }

            document.getElementById('financeBulkImportModal').classList.add('active');
        }

        function handleBulkCSVFileChange() {
            const fileInput = document.getElementById('csvFileInput');
            const fileNameSpan = document.getElementById('selectedFileName');
            if (fileInput.files && fileInput.files[0]) {
                fileNameSpan.textContent = '📄 ' + fileInput.files[0].name;
                fileNameSpan.style.display = 'inline-block';
            }
        }

        async function processBulkImportCSV() {
            const fileInput = document.getElementById('csvFileInput');
            if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                showToast('Please select a CSV file first', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('csv_file', fileInput.files[0]);

            const dash = document.getElementById('importResultDashboard');
            if (dash) dash.style.display = 'none';

            try {
                const res = await fetch('api/payroll_api.php?action=importPayrollCSV', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success && data.data) {
                    const info = data.data;
                    document.getElementById('resTotalRows').textContent = info.total_rows || 0;
                    document.getElementById('resUpdated').textContent = info.updated_count || 0;
                    document.getElementById('resSkipped').textContent = info.skipped_count || 0;
                    document.getElementById('resFailed').textContent = info.failed_count || 0;

                    const list = document.getElementById('importDetailsList');
                    list.innerHTML = '';
                    (info.updated || []).forEach(u => {
                        list.innerHTML += `<li style="color:#10b981; margin-bottom:4px;">✅ Employee ID ${u.code} (${u.name}): Basic ₨${Number(u.salary).toLocaleString()} | Appt: ${u.appointment_date}</li>`;
                    });
                    (info.skipped || []).forEach(s => {
                        list.innerHTML += `<li style="color:#f59e0b; margin-bottom:4px;">⚠️ Row ${s.row} (ID: ${s.code || 'N/A'}): ${s.reason}</li>`;
                    });
                    (info.failed || []).forEach(f => {
                        list.innerHTML += `<li style="color:#ef4444; margin-bottom:4px;">❌ Row ${f.row} (ID: ${f.code || 'N/A'}): ${f.reason}</li>`;
                    });

                    if (dash) dash.style.display = 'block';
                    showToast('✅ Bulk payroll CSV processed successfully!', 'success');
                    await loadFinanceUsers();
                    await initPage();
                } else {
                    showToast('❌ ' + (data.error || 'Failed to process CSV'), 'error');
                }
            } catch(e) {
                console.error(e);
                showToast('❌ Connection error uploading CSV', 'error');
            }
        }

        // ═══════════════════════════════════════════════════════════════════
        // PETTY CASH MODULE — JavaScript
        // ═══════════════════════════════════════════════════════════════════
        const PettyCash = (() => {
            const API = 'api/petty_cash_api.php';
            let _allRequests = [];
            let _pendingAction = null;
            let _pcCategoryChart = null;
            let _pcBranchChart = null;

            function fmt(n) {
                return '\u20a8 ' + Number(n || 0).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            function fmtDate(d) {
                if (!d) return '\u2014';
                const dt = new Date(d);
                return dt.toLocaleDateString('en-PK', { day: '2-digit', month: 'short', year: 'numeric' });
            }
            function fmtBranch(b) {
                const m = { main: 'Main Branch', commercial: 'Commercial', workfromhome: 'Work From Home' };
                return m[b] || b || '\u2014';
            }
            function statusBadge(s) {
                const map = {
                    submitted:       '<span class="pc-badge pc-badge-submitted">Submitted</span>',
                    approved:        '<span class="pc-badge pc-badge-approved">Approved</span>',
                    rejected:        '<span class="pc-badge pc-badge-rejected">Rejected</span>',
                    need_correction: '<span class="pc-badge pc-badge-need_correction">Needs Correction</span>',
                };
                return map[s] || `<span class="pc-badge">${s}</span>`;
            }
            function isLocked(s) { return s === 'approved' || s === 'rejected'; }
            function fileSz(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1048576).toFixed(1) + ' MB';
            }

            function init() {
                const nowMonth = new Date().toISOString().slice(0, 7);
                const dashMonth = document.getElementById('pcDashMonth');
                if (dashMonth && !dashMonth.value) dashMonth.value = nowMonth;
                const ledgerMonth = document.getElementById('pcLedgerMonth');
                if (ledgerMonth && !ledgerMonth.value) ledgerMonth.value = nowMonth;

                const fMonth = document.getElementById('pcFilterMonth');
                if (fMonth && fMonth.options.length === 1) {
                    const now = new Date();
                    for (let i = 0; i < 12; i++) {
                        const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
                        const val = d.toISOString().slice(0, 7);
                        const opt = document.createElement('option');
                        opt.value = val;
                        opt.textContent = d.toLocaleDateString('en-PK', { year: 'numeric', month: 'long' });
                        fMonth.appendChild(opt);
                    }
                }

                const reqBy = document.getElementById('pcRequestedBy');
                if (reqBy && !reqBy.value) {
                    const nameEl = document.getElementById('sidebarUserName');
                    if (nameEl) reqBy.value = nameEl.textContent.trim();
                }
                const dateEl = document.getElementById('pcExpenseDate');
                if (dateEl && !dateEl.value) dateEl.value = new Date().toISOString().slice(0, 10);

                const zone = document.getElementById('pcUploadZone');
                if (zone) {
                    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragging'); });
                    zone.addEventListener('dragleave', () => zone.classList.remove('dragging'));
                    zone.addEventListener('drop', e => {
                        e.preventDefault();
                        zone.classList.remove('dragging');
                        const fi = document.getElementById('pcBillFile');
                        if (e.dataTransfer.files.length) {
                            const dt = new DataTransfer();
                            dt.items.add(e.dataTransfer.files[0]);
                            fi.files = dt.files;
                            onFileSelect(fi);
                        }
                    });
                }
                loadCategories();
                loadDashboard();
            }

            function switchTab(tab) {
                document.querySelectorAll('.pc-tab').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.pc-tab-panel').forEach(p => p.classList.remove('active'));
                const btn = document.getElementById(`pct-${tab}`);
                const panel = document.getElementById(`pctp-${tab}`);
                if (btn) btn.classList.add('active');
                if (panel) panel.classList.add('active');
                if (tab === 'requests') loadRequests();
                if (tab === 'ledger') loadLedger();
                if (tab === 'dashboard') loadDashboard();
            }

            async function loadDashboard() {
                const month  = document.getElementById('pcDashMonth')?.value  || new Date().toISOString().slice(0, 7);
                const branch = document.getElementById('pcDashBranch')?.value || '';
                try {
                    const res  = await fetch(`${API}?action=getDashboard&month=${encodeURIComponent(month)}&branch=${encodeURIComponent(branch)}`);
                    const data = await res.json();
                    if (data.success) renderDashboard(data.data);
                } catch(e) { console.error('PC dashboard error:', e); }
            }

            function renderDashboard({ stats, categoryData, branchData }) {
                const grid = document.getElementById('pcDashStats');
                if (!grid) return;
                grid.innerHTML = `
                    <div class="pc-stat-card hero">
                        <div class="pc-stat-card-header">
                            <div class="pc-stat-icon hero-icon"><i class="fas fa-wallet"></i></div>
                            <span class="pc-badge pc-badge-approved">Live Sync</span>
                        </div>
                        <div>
                            <div class="pc-stat-value">${fmt(stats.approved_amount)}</div>
                            <div class="pc-stat-label">Total Approved Amount</div>
                        </div>
                    </div>
                    <div class="pc-stat-card hero-pending">
                        <div class="pc-stat-card-header">
                            <div class="pc-stat-icon hero-pending-icon"><i class="fas fa-hourglass-half"></i></div>
                            <span class="pc-badge pc-badge-need_correction">${stats.pending} Pending</span>
                        </div>
                        <div>
                            <div class="pc-stat-value">${fmt(stats.pending_amount)}</div>
                            <div class="pc-stat-label">Pending Disbursement</div>
                        </div>
                    </div>
                    <div class="pc-stat-card">
                        <div class="pc-stat-card-header">
                            <div class="pc-stat-icon blue"><i class="fas fa-inbox"></i></div>
                        </div>
                        <div>
                            <div class="pc-stat-value">${stats.total_submitted}</div>
                            <div class="pc-stat-label">Total Requests</div>
                        </div>
                    </div>
                    <div class="pc-stat-card">
                        <div class="pc-stat-card-header">
                            <div class="pc-stat-icon green"><i class="fas fa-check-circle"></i></div>
                        </div>
                        <div>
                            <div class="pc-stat-value">${stats.approved}</div>
                            <div class="pc-stat-label">Approved Requests</div>
                        </div>
                    </div>
                    <div class="pc-stat-card">
                        <div class="pc-stat-card-header">
                            <div class="pc-stat-icon yellow"><i class="fas fa-clock"></i></div>
                        </div>
                        <div>
                            <div class="pc-stat-value">${stats.pending}</div>
                            <div class="pc-stat-label">Pending Review</div>
                        </div>
                    </div>
                    <div class="pc-stat-card">
                        <div class="pc-stat-card-header">
                            <div class="pc-stat-icon red"><i class="fas fa-times-circle"></i></div>
                        </div>
                        <div>
                            <div class="pc-stat-value">${stats.rejected}</div>
                            <div class="pc-stat-label">Rejected Requests</div>
                        </div>
                    </div>
                    <div class="pc-stat-card">
                        <div class="pc-stat-card-header">
                            <div class="pc-stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                        </div>
                        <div>
                            <div class="pc-stat-value">${stats.need_correction}</div>
                            <div class="pc-stat-label">Need Correction</div>
                        </div>
                    </div>
                `;
                renderCategoryChart(categoryData);
                renderBranchChart(branchData);
            }

            function renderCategoryChart(data) {
                const canvas = document.getElementById('pcCategoryChart');
                if (!canvas) return;
                if (_pcCategoryChart) { _pcCategoryChart.destroy(); _pcCategoryChart = null; }
                const container = canvas.parentElement;
                
                const existingEmpty = container.querySelector('.pc-empty-overlay');
                if (existingEmpty) existingEmpty.remove();
                canvas.style.display = 'block';

                if (!data || data.length === 0) {
                    canvas.style.display = 'none';
                    const overlay = document.createElement('div');
                    overlay.className = 'pc-empty pc-empty-overlay';
                    overlay.style.padding = '30px 20px';
                    overlay.innerHTML = `<div class="pc-empty-icon"><i class="fas fa-chart-bar"></i></div><h4>No Data Available</h4><p>No approved expenses recorded for this month.</p>`;
                    container.appendChild(overlay);
                    return;
                }

                const ctx = canvas.getContext('2d');
                const gradient = ctx.createLinearGradient(0, 0, 0, 200);
                gradient.addColorStop(0, 'rgba(249, 115, 22, 0.85)');
                gradient.addColorStop(1, 'rgba(249, 115, 22, 0.15)');

                _pcCategoryChart = new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: data.map(d => d.label),
                        datasets: [{
                            data: data.map(d => d.value),
                            backgroundColor: gradient,
                            borderColor: '#f97316',
                            borderWidth: 2,
                            borderRadius: 10,
                            borderSkipped: false,
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { ticks: { color: '#94a3b8', font: { size: 11, weight: '600' } }, grid: { display: false } },
                            y: { ticks: { color: '#94a3b8', font: { size: 10 }, callback: v => '\u20a8'+Number(v).toLocaleString() }, grid: { color: 'rgba(255,255,255,0.05)' } }
                        }
                    }
                });
            }

            function renderBranchChart(data) {
                const canvas = document.getElementById('pcBranchChart');
                if (!canvas) return;
                if (_pcBranchChart) { _pcBranchChart.destroy(); _pcBranchChart = null; }
                const container = canvas.parentElement;

                // Clear any pre-existing empty overlay
                const existingEmpty = container.querySelector('.pc-empty-overlay');
                if (existingEmpty) existingEmpty.remove();
                canvas.style.display = 'block';

                if (!data || data.length === 0) {
                    canvas.style.display = 'none';
                    const overlay = document.createElement('div');
                    overlay.className = 'pc-empty pc-empty-overlay';
                    overlay.style.padding = '30px 20px';
                    overlay.innerHTML = `<div class="pc-empty-icon"><i class="fas fa-chart-pie"></i></div><h4>No Data Available</h4><p>No approved expenses recorded for this month.</p>`;
                    container.appendChild(overlay);
                    return;
                }
                _pcBranchChart = new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        labels: data.map(d => d.label),
                        datasets: [{ data: data.map(d => d.value), backgroundColor: ['rgba(249,115,22,0.8)','rgba(16,185,129,0.8)','rgba(59,130,246,0.8)'], borderColor: ['#f97316','#10b981','#3b82f6'], borderWidth: 2, hoverOffset: 8 }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '65%',
                        plugins: {
                            legend: { position: 'bottom', labels: { color: '#94a3b8', padding: 14, font: { size: 11 } } },
                            tooltip: { callbacks: { label: ctx => ` \u20a8 ${Number(ctx.parsed).toLocaleString('en-PK', { minimumFractionDigits: 2 })}` } }
                        }
                    }
                });
            }

            async function loadRequests() {
                const tbody = document.getElementById('pcRequestsTbody');
                if (!tbody) return;
                tbody.innerHTML = `<tr><td colspan="12"><div class="loading-state"><div class="loading-spinner"></div><p>Loading...</p></div></td></tr>`;
                const month    = encodeURIComponent(document.getElementById('pcFilterMonth')?.value    || '');
                const branch   = encodeURIComponent(document.getElementById('pcFilterBranch')?.value   || '');
                const category = encodeURIComponent(document.getElementById('pcFilterCategory')?.value || '');
                const status   = encodeURIComponent(document.getElementById('pcFilterStatus')?.value   || '');
                try {
                    const res = await fetch(`${API}?action=getRequests&month=${month}&branch=${branch}&category=${category}&status=${status}`);
                    const data = await res.json();
                    if (!data.success) { tbody.innerHTML = `<tr><td colspan="12"><div class="pc-empty"><div class="pc-empty-icon"><i class="fas fa-exclamation-circle"></i></div><h4>Error loading data</h4></div></td></tr>`; return; }
                    _allRequests = data.data || [];
                    renderRequestsTable(_allRequests);
                } catch(e) {
                    tbody.innerHTML = `<tr><td colspan="12"><div class="pc-empty"><div class="pc-empty-icon"><i class="fas fa-wifi"></i></div><h4>Connection Error</h4></div></td></tr>`;
                }
            }

            function filterRequests() {
                const q = (document.getElementById('pcSearchQ')?.value || '').toLowerCase().trim();
                const filtered = q === '' ? _allRequests : _allRequests.filter(r =>
                    (r.item_name||'').toLowerCase().includes(q) ||
                    (r.vendor_name||'').toLowerCase().includes(q) ||
                    (r.requested_by||'').toLowerCase().includes(q) ||
                    (r.category||'').toLowerCase().includes(q) ||
                    (r.bill_number||'').toLowerCase().includes(q)
                );
                renderRequestsTable(filtered);
            }

            function renderRequestsTable(rows) {
                const tbody = document.getElementById('pcRequestsTbody');
                const count = document.getElementById('pcReqCount');
                if (!tbody) return;
                if (count) count.textContent = `\u2014 ${rows.length} record${rows.length !== 1 ? 's' : ''}`;
                if (rows.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="12"><div class="pc-empty"><div class="pc-empty-icon"><i class="fas fa-receipt"></i></div><h4>No requests found</h4><p>Adjust your filters or submit a new request.</p></div></td></tr>`;
                    return;
                }
                tbody.innerHTML = rows.map((r) => {
                    const locked = isLocked(r.status);
                    const safeName = (r.item_name || '').replace(/'/g, "\\'").replace(/"/g, "&quot;");
                    const acts = locked
                        ? `<span class="pc-locked-tag"><i class="fas fa-lock"></i> Locked</span>`
                        : `<button class="pc-action-btn pc-btn-approve" title="Approve" onclick="PettyCash.openActionModal(${r.id},'approved','${safeName}',${r.amount})"><i class="fas fa-check"></i></button>
                           <button class="pc-action-btn pc-btn-correction" title="Need Correction" onclick="PettyCash.openActionModal(${r.id},'need_correction','${safeName}',${r.amount})"><i class="fas fa-edit"></i></button>
                           <button class="pc-action-btn pc-btn-reject" title="Reject" onclick="PettyCash.openActionModal(${r.id},'rejected','${safeName}',${r.amount})"><i class="fas fa-times"></i></button>
                           <button class="pc-action-btn pc-btn-delete" title="Delete" onclick="PettyCash.deleteRequest(${r.id})"><i class="fas fa-trash"></i></button>`;
                    const billBtn = r.bill_file_path
                        ? `<button class="pc-action-btn pc-btn-view" title="View Bill" onclick="PettyCash.viewBill('${r.bill_file_path}')"><i class="fas fa-eye"></i></button>`
                        : `<span style="color:var(--text-muted);font-size:10px;">\u2014</span>`;
                    return `<tr>
                        <td style="color:var(--text-muted);font-size:11px;">#${r.id}</td>
                        <td style="white-space:nowrap;">${fmtDate(r.expense_date)}</td>
                        <td style="font-weight:600;color:white;max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(r.item_name)}">${escapeHtml(r.item_name)}</td>
                        <td><span class="pc-category-pill">${escapeHtml(r.category)}</span></td>
                        <td style="white-space:nowrap;">${fmtBranch(r.branch)}</td>
                        <td style="color:var(--text-muted);">${escapeHtml(r.vendor_name||'\u2014')}</td>
                        <td class="amount-cell">${fmt(r.amount)}</td>
                        <td style="white-space:nowrap;">${escapeHtml(r.requested_by)}</td>
                        <td>${statusBadge(r.status)}</td>
                        <td style="font-size:11px;color:var(--text-muted);">${r.action_by?escapeHtml(r.action_by):'\u2014'}</td>
                        <td style="text-align:center;">${billBtn}</td>
                        <td style="text-align:center;white-space:nowrap;"><div style="display:flex;gap:4px;justify-content:center;">${acts}</div></td>
                    </tr>`;
                }).join('');
            }

            async function loadLedger() {
                const tbody = document.getElementById('pcLedgerTbody');
                const totalDiv = document.getElementById('pcLedgerTotal');
                const catCard = document.getElementById('pcLedgerCategoryCard');
                const catGrid = document.getElementById('pcLedgerCategoryGrid');
                if (!tbody) return;

                tbody.innerHTML = `<tr><td colspan="11"><div class="loading-state"><div class="loading-spinner"></div><p>Loading ledger...</p></div></td></tr>`;
                if (totalDiv) totalDiv.style.display = 'none';
                if (catCard) catCard.style.display = 'none';

                const month  = encodeURIComponent(document.getElementById('pcLedgerMonth')?.value  || new Date().toISOString().slice(0,7));
                const branch = encodeURIComponent(document.getElementById('pcLedgerBranch')?.value || '');
                try {
                    const res = await fetch(`${API}?action=getLedger&month=${month}&branch=${branch}`);
                    const data = await res.json();
                    if (!data.success) return;
                    const { rows, total, categoryBreakdown } = data.data;
                    if (!rows || rows.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="11"><div class="pc-empty"><div class="pc-empty-icon"><i class="fas fa-book"></i></div><h4>No approved expenses</h4><p>No approved petty cash for this month and branch.</p></div></td></tr>`;
                        return;
                    }
                    tbody.innerHTML = rows.map((r, i) => `
                        <tr>
                            <td style="color:var(--text-muted);font-size:11px;">${i+1}</td>
                            <td style="white-space:nowrap;">${fmtDate(r.expense_date)}</td>
                            <td style="font-weight:600;color:white;">${escapeHtml(r.item_name)}</td>
                            <td><span class="pc-category-pill">${escapeHtml(r.category)}</span></td>
                            <td>${fmtBranch(r.branch)}</td>
                            <td style="color:var(--text-muted);">${escapeHtml(r.vendor_name||'\u2014')}</td>
                            <td style="color:var(--text-muted);font-size:11px;font-family:monospace;">${escapeHtml(r.bill_number||'\u2014')}</td>
                            <td>${escapeHtml(r.requested_by)}</td>
                            <td style="color:var(--secondary);font-weight:600;">${escapeHtml(r.action_by||'\u2014')}</td>
                            <td style="font-size:11px;color:var(--text-muted);">${r.action_at?fmtDate(r.action_at):'\u2014'}</td>
                            <td class="amount-cell" style="text-align:right;">${fmt(r.amount)}</td>
                        </tr>
                    `).join('');

                    // Render category breakdown grid
                    if (categoryBreakdown && categoryBreakdown.length > 0 && catGrid && catCard) {
                        catGrid.innerHTML = categoryBreakdown.map(item => `
                            <div class="pc-category-summary-item">
                                <span><i class="fas fa-tag" style="color:var(--primary); margin-right:6px;"></i>${escapeHtml(item.category)}</span>
                                <strong>${fmt(item.total)}</strong>
                            </div>
                        `).join('');
                        catCard.style.display = 'block';
                    }

                    if (totalDiv) {
                        totalDiv.style.display = 'flex';
                        document.getElementById('pcLedgerTotalAmt').textContent = fmt(total);
                    }
                } catch(e) {
                    tbody.innerHTML = `<tr><td colspan="11"><div class="pc-empty"><div class="pc-empty-icon"><i class="fas fa-wifi"></i></div><h4>Connection Error</h4></div></td></tr>`;
                }
            }

            function onFileSelect(input) {
                const file = input.files[0];
                if (!file) return;
                const preview = document.getElementById('pcUploadPreview');
                const thumb   = document.getElementById('pcPreviewThumb');
                document.getElementById('pcPreviewName').textContent = file.name;
                document.getElementById('pcPreviewSize').textContent = fileSz(file.size);
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = e => { thumb.src = e.target.result; thumb.style.display = 'block'; };
                    reader.readAsDataURL(file);
                } else {
                    thumb.style.display = 'none'; thumb.src = '';
                }
                preview.classList.add('show');
            }

            function clearFile() {
                document.getElementById('pcBillFile').value = '';
                document.getElementById('pcUploadPreview').classList.remove('show');
                document.getElementById('pcPreviewThumb').src = '';
            }

            async function loadCategories() {
                try {
                    const res = await fetch(`${API}?action=getCategories`);
                    const data = await res.json();
                    if (!data.success || !data.data) return;
                    
                    const select = document.getElementById('pcCategorySelect');
                    const filterSelect = document.getElementById('pcFilterCategory');
                    if (!select) return;

                    const curVal = select.value;
                    let html = '<option value="">Select Category</option>';
                    data.data.forEach(cat => {
                        html += `<option value="${escapeHtml(cat)}">${escapeHtml(cat)}</option>`;
                    });
                    html += '<option value="__CUSTOM__">+ Write Custom Category...</option>';
                    select.innerHTML = html;
                    if (curVal && curVal !== '__CUSTOM__') select.value = curVal;

                    if (filterSelect) {
                        const curFVal = filterSelect.value;
                        let fHtml = '<option value="">All Categories</option>';
                        data.data.forEach(cat => {
                            fHtml += `<option value="${escapeHtml(cat)}">${escapeHtml(cat)}</option>`;
                        });
                        filterSelect.innerHTML = fHtml;
                        if (curFVal) filterSelect.value = curFVal;
                    }
                } catch (e) {
                    console.error('Error loading categories:', e);
                }
            }

            function toggleCustomCategory(forceCustom = false) {
                const select = document.getElementById('pcCategorySelect');
                const input = document.getElementById('pcCustomCategoryInput');
                const btn = document.getElementById('pcToggleCustomCategory');
                if (!select || !input) return;

                if (forceCustom || input.style.display === 'none') {
                    select.style.display = 'none';
                    select.removeAttribute('required');
                    input.style.display = 'block';
                    input.setAttribute('required', 'required');
                    input.focus();
                    if (btn) btn.textContent = '← Select Existing';
                } else {
                    input.style.display = 'none';
                    input.removeAttribute('required');
                    input.value = '';
                    select.style.display = 'block';
                    select.setAttribute('required', 'required');
                    select.value = '';
                    if (btn) btn.textContent = '+ Custom';
                }
            }

            function onCategorySelectChange(select) {
                if (select.value === '__CUSTOM__') {
                    toggleCustomCategory(true);
                }
            }

            async function submitRequest() {
                const btn = document.getElementById('pcSubmitBtn');
                
                // Handle Category value from dropdown or custom input
                let categoryVal = '';
                const selectEl = document.getElementById('pcCategorySelect');
                const customInputEl = document.getElementById('pcCustomCategoryInput');
                if (customInputEl && customInputEl.style.display !== 'none') {
                    categoryVal = customInputEl.value.trim();
                } else if (selectEl) {
                    categoryVal = selectEl.value.trim();
                }

                const fields = {
                    expense_date: document.getElementById('pcExpenseDate')?.value,
                    branch:       document.getElementById('pcBranch')?.value,
                    category:     categoryVal,
                    item_name:    document.getElementById('pcItemName')?.value?.trim(),
                    amount:       document.getElementById('pcAmount')?.value,
                    requested_by: document.getElementById('pcRequestedBy')?.value?.trim(),
                };
                for (const [k, v] of Object.entries(fields)) {
                    if (!v) { showToast(`\u26a0\ufe0f Please fill in: ${k.replace(/_/g,' ')}`, 'error'); return; }
                }
                const fileInput = document.getElementById('pcBillFile');
                if (!fileInput.files[0]) { showToast('\u26a0\ufe0f Bill/slip upload is required', 'error'); return; }
                const fd = new FormData();
                Object.entries(fields).forEach(([k,v]) => fd.append(k, v));
                fd.append('description', document.getElementById('pcDescription')?.value || '');
                fd.append('vendor_name', document.getElementById('pcVendorName')?.value  || '');
                fd.append('bill_number', document.getElementById('pcBillNumber')?.value  || '');
                fd.append('remarks',     document.getElementById('pcRemarks')?.value     || '');
                fd.append('bill_file',   fileInput.files[0]);
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                try {
                    const res  = await fetch(`${API}?action=submitRequest`, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        showToast('\u2705 Petty Cash request submitted successfully!', 'success');
                        resetForm();
                        await loadCategories();
                        loadDashboard();
                        switchTab('requests');
                    } else {
                        showToast('\u274c ' + (data.message || 'Submission failed'), 'error');
                    }
                } catch(e) {
                    showToast('\u274c Connection error. Please try again.', 'error');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Petty Cash Request';
                }
            }

            function resetForm() {
                document.getElementById('pcRequestForm')?.reset();
                clearFile();
                toggleCustomCategory(false);
                const dateEl = document.getElementById('pcExpenseDate');
                if (dateEl) dateEl.value = new Date().toISOString().slice(0, 10);
                const nameEl = document.getElementById('sidebarUserName');
                const reqBy  = document.getElementById('pcRequestedBy');
                if (reqBy && nameEl) reqBy.value = nameEl.textContent.trim();
            }

            function openActionModal(id, action, itemName, amount) {
                _pendingAction = { id, action };
                const modal      = document.getElementById('pcActionModal');
                const title      = document.getElementById('pcActionModalTitle');
                const info       = document.getElementById('pcActionRequestInfo');
                const confirmBtn = document.getElementById('pcActionConfirmBtn');
                document.getElementById('pcActionRemarks').value = '';
                const config = {
                    approved:        { icon:'fa-check-circle', color:'var(--secondary)', label:'Approve Request',    cls:'btn-success', txt:'<i class="fas fa-check"></i> Approve' },
                    rejected:        { icon:'fa-times-circle', color:'var(--danger)',    label:'Reject Request',     cls:'btn-danger',  txt:'<i class="fas fa-times"></i> Reject' },
                    need_correction: { icon:'fa-edit',         color:'var(--warning)',   label:'Request Correction', cls:'btn-warning', txt:'<i class="fas fa-edit"></i> Mark for Correction' },
                };
                const cfg = config[action] || config.approved;
                title.innerHTML = `<i class="fas ${cfg.icon}" style="color:${cfg.color};"></i> ${cfg.label}`;
                info.innerHTML  = `<strong style="color:white;display:block;margin-bottom:4px;">${escapeHtml(itemName)}</strong>Amount: <strong style="color:var(--secondary);">${fmt(amount)}</strong>`;
                confirmBtn.className = `btn ${cfg.cls}`;
                confirmBtn.innerHTML = cfg.txt;
                modal.classList.add('active');
            }

            function closeActionModal() {
                document.getElementById('pcActionModal')?.classList.remove('active');
                _pendingAction = null;
            }

            async function confirmAction() {
                if (!_pendingAction) return;
                const { id, action } = _pendingAction;
                const remarks = document.getElementById('pcActionRemarks')?.value || '';
                try {
                    const res  = await fetch(`${API}?action=updateAction`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id, action, remarks }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        showToast(`\u2705 Request ${action.replace('_',' ')} successfully!`, 'success');
                        closeActionModal();
                        loadRequests();
                        loadDashboard();
                    } else {
                        showToast('\u274c ' + (data.message || 'Action failed'), 'error');
                    }
                } catch(e) { showToast('\u274c Connection error', 'error'); }
            }

            async function deleteRequest(id) {
                if (!confirm('Delete this request? This cannot be undone.')) return;
                try {
                    const res  = await fetch(`${API}?action=deleteRequest`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id }),
                    });
                    const data = await res.json();
                    if (data.success) { showToast('\u2705 Request deleted.', 'success'); loadRequests(); loadDashboard(); }
                    else showToast('\u274c ' + (data.message || 'Delete failed'), 'error');
                } catch(e) { showToast('\u274c Connection error', 'error'); }
            }

            function viewBill(filePath) {
                const modal  = document.getElementById('pcBillModal');
                const viewer = document.getElementById('pcBillViewer');
                const dlLink = document.getElementById('pcBillDownloadLink');
                const isPDF  = filePath.toLowerCase().endsWith('.pdf');
                dlLink.href  = filePath;
                viewer.innerHTML = isPDF
                    ? `<iframe src="${filePath}" style="width:100%;height:65vh;border:none;border-radius:10px;"></iframe>`
                    : `<img src="${filePath}" alt="Bill" style="max-width:100%;max-height:65vh;border-radius:10px;object-fit:contain;" onerror="this.outerHTML='<div style=\'color:var(--text-muted);text-align:center;padding:40px\'><i class=\'fas fa-image\' style=\'font-size:48px;display:block;margin-bottom:12px\'></i>Cannot display image</div>'">`;
                modal.classList.add('active');
            }

            function closeBillModal() {
                document.getElementById('pcBillModal')?.classList.remove('active');
                document.getElementById('pcBillViewer').innerHTML = '';
            }

            function exportCSV() {
                const month    = document.getElementById('pcFilterMonth')?.value    || '';
                const branch   = document.getElementById('pcFilterBranch')?.value   || '';
                const category = document.getElementById('pcFilterCategory')?.value || '';
                const status   = document.getElementById('pcFilterStatus')?.value   || '';
                window.open(`${API}?action=exportCSV&month=${encodeURIComponent(month)}&branch=${encodeURIComponent(branch)}&category=${encodeURIComponent(category)}&status=${encodeURIComponent(status)}`, '_blank');
            }

            function exportLedgerCSV() {
                const month  = document.getElementById('pcLedgerMonth')?.value  || '';
                const branch = document.getElementById('pcLedgerBranch')?.value || '';
                window.open(`${API}?action=exportCSV&month=${encodeURIComponent(month)}&branch=${encodeURIComponent(branch)}&status=approved`, '_blank');
            }

            // Extend modal button styles dynamically
            (() => {
                const s = document.createElement('style');
                s.textContent = '.btn-danger{background:var(--danger);color:white;}.btn-danger:hover{background:#dc2626;}.btn-warning{background:var(--warning);color:#0f172a;}.btn-warning:hover{background:#d97706;}';
                document.head.appendChild(s);
            })();

            return {
                init, switchTab,
                loadDashboard, loadRequests, loadLedger, loadCategories,
                filterRequests,
                onFileSelect, clearFile, resetForm, submitRequest,
                toggleCustomCategory, onCategorySelectChange,
                openActionModal, closeActionModal, confirmAction,
                deleteRequest, viewBill, closeBillModal,
                exportCSV, exportLedgerCSV,
            };
        })();
        // ═══════════════════════════════════════════════════════════════════

        initPage();
    