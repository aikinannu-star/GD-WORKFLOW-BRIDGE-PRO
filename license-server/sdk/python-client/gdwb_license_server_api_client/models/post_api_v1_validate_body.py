from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset

from ..types import UNSET, Unset






T = TypeVar("T", bound="PostApiV1ValidateBody")



@_attrs_define
class PostApiV1ValidateBody:
    """ 
        Attributes:
            license_key (str):
            site (str | Unset):
            plan (str | Unset):
     """

    license_key: str
    site: str | Unset = UNSET
    plan: str | Unset = UNSET
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        license_key = self.license_key

        site = self.site

        plan = self.plan


        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
            "license_key": license_key,
        })
        if site is not UNSET:
            field_dict["site"] = site
        if plan is not UNSET:
            field_dict["plan"] = plan

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        d = dict(src_dict)
        license_key = d.pop("license_key")

        site = d.pop("site", UNSET)

        plan = d.pop("plan", UNSET)

        post_api_v1_validate_body = cls(
            license_key=license_key,
            site=site,
            plan=plan,
        )


        post_api_v1_validate_body.additional_properties = d
        return post_api_v1_validate_body

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
