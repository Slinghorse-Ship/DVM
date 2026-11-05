"""Private identity for byte-exact replays of original XML validation receipts.

Normal synthetic tests choose their own test identities. These original receipts
must not be relabelled with a different identity or silently recomputed.
"""
import os
import re
import unittest


def original_manufacturer_id():
    value = os.environ.get("DVM_ORIGINAL_MANUFACTURER_ID", "")
    if not value:
        raise unittest.SkipTest("Original XML replay requires private DVM_ORIGINAL_MANUFACTURER_ID.")
    if re.fullmatch(r"[0-9]{5}", value) is None:
        raise ValueError("Private original manufacturer identity must contain five ASCII digits.")
    return value
