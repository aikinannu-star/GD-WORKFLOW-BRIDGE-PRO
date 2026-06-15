from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset







T = TypeVar("T", bound="RevokeRequest")



@_attrs_define
class RevokeRequest:
    """ 
        Attributes:
            license_key (str):
            admin_secret (str):
     """

    license_key: str
    admin_secret: str
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        license_key = self.license_key

        admin_secret = self.admin_secret


        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
            "license_key": license_key,
            "admin_secret": admin_secret,
        })

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        d = dict(src_dict)
        license_key = d.pop("license_key")

        admin_secret = d.pop("admin_secret")

        revoke_request = cls(
            license_key=license_key,
            admin_secret=admin_secret,
        )


        revoke_request.additional_properties = d
        return revoke_request

    @property
    def additional_keys(self) -> list[str]:
        return list(self.additional_properties.keys())

    def __getitem__(self, key: str) -> Any:
        return self.additional_properties[key]

    def __setitem__(self, key: str, value: Any) -> None:
        self.additional_properties[key] = value

    def __delitem__(self, key: str) -> None:
        del self.additional_properties[key]

    def __contains__(self, key: str) -> bool:
        return key in self.additional_properties
