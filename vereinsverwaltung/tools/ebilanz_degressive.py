"""Independent cent-accurate validation of the confirmed §7(2)/(3) asset plan."""
from datetime import date
from ebilanz_asset_types import MOVABLE


def depreciation(cost, acquired, months, proof, year, account, residual, through_month=12):
    if (not isinstance(proof,dict) or set(proof)!={'rate_basis_points','movable','same_book_tax','switch_to_linear'}
            or type(proof['rate_basis_points']) is not int or type(proof['switch_to_linear']) is not bool
            or proof['movable'] is not True or proof['same_book_tax'] is not True):
        raise ValueError('Anlagenspiegel: Nachweis der degressiven Abschreibung fehlt.')
    rate=proof['rate_basis_points']
    if (not date(2025,7,1)<=acquired<=date(2026,12,31) or type(months) is not int
            or not 12<=months<=1200 or months%12 or not 0<rate<=3000 or rate*months>360000
            or account not in MOVABLE or residual!=0):
        raise ValueError('Anlagenspiegel: Anschaffung, Höchstsatz oder Anlagekonto der degressiven Abschreibung prüfen.')
    if type(through_month) is not int or not 1<=through_month<=12:raise ValueError('Abschreibungsmonat prüfen.')
    value=cost;elapsed=0;linear_basis=None;linear_used=0;linear_months=0
    opening=value
    for current in range(acquired.year,year+1):
        active=13-acquired.month if current==acquired.year else 12
        remaining=max(0,months-elapsed);opening=value
        declining=(value*rate*active+60000)//120000
        straight=(value*min(remaining,active)+remaining//2)//remaining if remaining else value
        if linear_basis is None and proof['switch_to_linear'] and straight>=declining:
            linear_basis=value;linear_months=max(1,remaining)
        if current==year:
            active=max(0,through_month-(acquired.month-1 if current==acquired.year else 0))
            declining=(value*rate*active+60000)//120000
        if linear_basis is None:
            charge=declining
        else:
            before=linear_used;linear_used=min(linear_months,linear_used+active)
            charge=((linear_basis*linear_used+linear_months//2)//linear_months
                    -(linear_basis*before+linear_months//2)//linear_months)
        value-=min(value,charge);elapsed+=active
    return cost-opening,cost-value
