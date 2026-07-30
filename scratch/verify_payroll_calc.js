// Scratch verification script for calculatePayrollForEmployee logic
function getCalculationEndDay() { return 30; }

function calculatePayrollForEmployee(emp, currentYear = 2026, currentMonth = 7, elapsedWorkingDaysCount = 22, workingDaysCount = 22) {
    const basicSalary = 50000;
    const punctualityBonus = 5000;
    const meta = { punctualityEnabled: true };
    const perDaySalary = basicSalary / workingDaysCount;

    const rawAbsence = Math.max(0, elapsedWorkingDaysCount - emp.present);
    const adjustedLeaveCount = emp.leave > 0 ? 1 : 0;
    const adjustedAbsent = Math.max(0, rawAbsence - adjustedLeaveCount);
    const totalWorkingDays = emp.present + adjustedLeaveCount;
    const payrollMonthComplete = true;

    let tenureDays = 0;
    let isTenure60Plus = false;
    if (emp.appointmentDate) {
        const apptDate = new Date(emp.appointmentDate);
        if (!Number.isNaN(apptDate.getTime())) {
            const calcDate = new Date(currentYear, currentMonth - 1, 30);
            tenureDays = Math.floor((calcDate - apptDate) / (1000 * 60 * 60 * 24));
            if (tenureDays >= 60) isTenure60Plus = true;
        }
    }

    let punctualityQualified = false;
    let punctualityAmount = 0;
    if (meta.punctualityEnabled && payrollMonthComplete && emp.late === 0 && adjustedAbsent === 0) {
        const leaveAllowancePermitted = isTenure60Plus ? (adjustedLeaveCount <= 1) : (adjustedLeaveCount === 0);
        if (leaveAllowancePermitted && totalWorkingDays === workingDaysCount) {
            punctualityQualified = true;
            punctualityAmount = punctualityBonus;
        }
    }

    const bonus = emp.bonus || 0;
    const tada = emp.tada || 0;
    const arrears = 0;
    const extraDayPay = 0;

    const lateDeduction = emp.late > 3 ? emp.late * 300 : 0;
    const halfDayAmount = 0;
    const unpaidDeduction = 0;
    const ncnsAmount = 0;
    const sdAmount = 0;
    const qaHrAmount = 0;
    const misspunchAmount = 0;
    const advanceDeduction = 0;
    const absentDeduction = adjustedAbsent * perDaySalary;

    const tax = emp.tax || 0;

    const earningsBase = totalWorkingDays * perDaySalary;
    const totalAdditions = punctualityAmount + bonus + tada + arrears + extraDayPay;
    const totalEarnings = earningsBase + totalAdditions;

    const nonTaxDeductions = absentDeduction + lateDeduction + halfDayAmount + ncnsAmount + sdAmount + qaHrAmount + misspunchAmount + advanceDeduction + unpaidDeduction;
    const subNetSalary = Math.max(0, totalEarnings - nonTaxDeductions);
    const finalNetSalary = Math.max(0, subNetSalary - tax);

    return {
        tenureDays,
        isTenure60Plus,
        punctualityQualified,
        punctualityAmount,
        totalEarnings,
        nonTaxDeductions,
        subNetSalary,
        tax,
        finalNetSalary
    };
}

console.log("--- TEST CASE 1: Appt 90 days ago (>=60 days), 1 leave ---");
console.log(calculatePayrollForEmployee({ id: 'E1', appointmentDate: '2026-04-01', present: 21, leave: 1, late: 0, bonus: 2000, tax: 500 }));

console.log("\n--- TEST CASE 2: Appt 30 days ago (<60 days), 1 leave ---");
console.log(calculatePayrollForEmployee({ id: 'E2', appointmentDate: '2026-06-30', present: 21, leave: 1, late: 0, bonus: 2000, tax: 500 }));

console.log("\n--- TEST CASE 3: Appt 90 days ago, 2 leaves (disqualifies) ---");
console.log(calculatePayrollForEmployee({ id: 'E3', appointmentDate: '2026-04-01', present: 20, leave: 2, late: 0, bonus: 0, tax: 0 }));
