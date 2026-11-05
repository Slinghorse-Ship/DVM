import os
import unittest
from unittest.mock import patch
from original_manufacturer import original_manufacturer_id


class OriginalManufacturerIdentity(unittest.TestCase):
    def test_missing_identity_skips_original_replay(self):
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(unittest.SkipTest):
                original_manufacturer_id()

    def test_explicit_synthetic_identity_passes_without_mutating_environment(self):
        with patch.dict(os.environ, {"DVM_ORIGINAL_MANUFACTURER_ID": "12345"}, clear=True):
            self.assertEqual(original_manufacturer_id(), "12345")
            self.assertEqual(dict(os.environ), {"DVM_ORIGINAL_MANUFACTURER_ID": "12345"})

    def test_invalid_configuration_fails_instead_of_claiming_a_replay(self):
        for value in ["1234", "123456", "ABCDE", "１２３４５", "12345\n"]:
            with self.subTest(value=value):
                with patch.dict(os.environ, {"DVM_ORIGINAL_MANUFACTURER_ID": value}, clear=True):
                    with self.assertRaises(ValueError):
                        original_manufacturer_id()


if __name__ == "__main__":
    unittest.main()
